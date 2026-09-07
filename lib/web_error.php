<?php
/**
 * Shared web error reporting: turns a Noiiolelo\ProviderUnavailableException
 * (or any other Throwable escaping an entry point) into a clear, user-visible
 * response instead of a fatal error / blank screen.
 *
 * Entry points wrap their getProvider() call like this:
 *
 *   require_once __DIR__ . '/lib/web_error.php';
 *   try {
 *       $provider = getProvider();
 *   } catch (\Throwable $e) {
 *       noiiolelo_render_error('page', $e);  // renders and exits
 *   }
 *
 * Formats:
 *   'page'     standalone HTML page (full-page entry points)
 *   'fragment' HTML fragment box (AJAX endpoints whose output is inserted
 *              into the live page)
 *   'json'     {"error": "..."} (API/JSON endpoints)
 *   'text'     plain text (text/plain endpoints)
 *
 * Status codes: 503 Service Unavailable for a provider outage, 500 for any
 * other unexpected failure. Under CLI (e.g. ops/getPageHtml.php invoked from
 * a shell) the message goes to STDERR with exit code 1 instead of markup.
 */

/**
 * HTTP status for the failure: 503 when the backend is down, 500 otherwise.
 */
function noiiolelo_error_status(\Throwable $e): int
{
    return $e instanceof \Noiiolelo\ProviderUnavailableException ? 503 : 500;
}

/**
 * Message safe to show a site visitor: names the provider when it is an
 * availability problem, never leaks the underlying transport error.
 *
 * $withRetryHint appends "Please try again..." for formats that offer no
 * other action (fragment/json/text). The full error page omits it — the
 * provider dropdown is the action instead.
 */
function noiiolelo_user_message(\Throwable $e, bool $withRetryHint = true): string
{
    if ($e instanceof \Noiiolelo\ProviderUnavailableException) {
        $provider = htmlspecialchars($e->getProviderName(), ENT_QUOTES);
        $msg = "The $provider search provider is temporarily unavailable.";
    } else {
        $msg = 'Something went wrong.';
    }
    return $withRetryHint ? "$msg Please try again in a few minutes." : $msg;
}

/**
 * Build the standalone error page body without sending anything: friendly
 * message plus a dropdown of the providers that are currently reachable.
 * Submitting navigates to the same page with the selected provider while
 * preserving the rest of the query context (search term, pattern, ...).
 * Returned as a string so it is unit-testable; noiiolelo_render_error()
 * echoes it.
 */
function noiiolelo_error_page_html(\Throwable $e): string
{
    $msg = htmlspecialchars(noiiolelo_user_message($e, false), ENT_QUOTES);
    $statusText = (noiiolelo_error_status($e) === 503) ? 'Search Unavailable' : 'Error';

    $path = htmlspecialchars(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', ENT_QUOTES);

    // Preserve the current page context except the provider itself, which
    // the dropdown replaces.
    $hidden = '';
    foreach ($_GET as $key => $value) {
        if ($key === 'provider') {
            continue;
        }
        $hidden .= sprintf(
            '<input type="hidden" name="%s" value="%s">' . "\n",
            htmlspecialchars((string) $key, ENT_QUOTES),
            htmlspecialchars((string) $value, ENT_QUOTES)
        );
    }

    $available = getAvailableProviders();
    if ($available) {
        $options = '';
        foreach ($available as $name) {
            $safe = htmlspecialchars($name, ENT_QUOTES);
            $options .= "<option value=\"$safe\">$safe</option>\n";
        }
        $switch = <<<HTML
<p>Try another search provider:</p>
<form method="get" action="$path">
    $hidden<select name="provider">
$options</select>
    <button type="submit">Go</button>
</form>
HTML;
    } else {
        $switch = '<p>No search providers are currently available.</p>';
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Noiʻiʻōlelo — $statusText</title>
<style>
body { font-family: sans-serif; background: #f5f1e8; color: #333; margin: 0; }
.errorbox { max-width: 40em; margin: 4em auto; padding: 2em; background: #fff; border: 1px solid #d8cfc0; border-radius: 8px; text-align: center; }
.errorbox h1 { font-size: 1.4em; margin-top: 0; }
.errorbox a { color: #4a6fa5; }
.errorbox form { margin-top: 0.5em; }
.errorbox select, .errorbox button { font-size: 1em; padding: 0.3em 0.6em; border-radius: 6px; border: 1px solid #b9ac99; background: #fff; }
.errorbox button { cursor: pointer; }
</style>
</head>
<body>
<div class="errorbox">
<h1>Noiʻiʻōlelo</h1>
<p>$msg</p>
$switch
</div>
</body>
</html>
HTML;
}

/**
 * Render the failure in the requested format and terminate the request.
 * Details of every failure are logged; only the friendly message is shown.
 */
function noiiolelo_render_error(string $format, \Throwable $e): void
{
    \Avisitor\Monolog\Logger::logError(
        'web error (' . $format . '): ' . get_class($e) . ': ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine()
    );

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, noiiolelo_user_message($e) . "\n");
        exit(1);
    }

    // Once output has started the status code can no longer be set and a
    // full page cannot replace the partial response; append a visible error
    // box to whatever was rendered so far so the failure is never silent.
    if (headers_sent()) {
        $msg = htmlspecialchars(noiiolelo_user_message($e), ENT_QUOTES);
        echo "<div class=\"provider-unavailable\" role=\"alert\">$msg</div>";
        exit;
    }

    $status = noiiolelo_error_status($e);
    http_response_code($status);

    switch ($format) {
        case 'json':
            header('Content-Type: application/json');
            echo json_encode(['error' => noiiolelo_user_message($e)]);
            break;
        case 'text':
            header('Content-Type: text/plain; charset=utf-8');
            echo noiiolelo_user_message($e) . "\n";
            break;
        case 'fragment':
            $msg = htmlspecialchars(noiiolelo_user_message($e), ENT_QUOTES);
            echo "<div class=\"provider-unavailable\" role=\"alert\">$msg</div>";
            break;
        case 'page':
        default:
            echo noiiolelo_error_page_html($e);
            break;
    }
    exit;
}
