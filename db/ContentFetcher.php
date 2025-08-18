<?php

class ContentFetcher {
    /**
     * Fetches content from a URL using file_get_contents.
     *
     * @param string $url The URL to fetch.
     * @return string The content of the URL, or an empty string on failure.
     */
    public function fetch($url) {
        $url = trim($url);
        if (empty($url)) {
            return "";
        }

        // Use error handling to suppress warnings on failure and set a user agent
        $context = stream_context_create([
            'http' => [
                'ignore_errors' => true,
                'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.36\r\n"
            ]
        ]);

        $html = @file_get_contents($url, false, $context);

        return ($html === false) ? "" : $html;
    }
}

