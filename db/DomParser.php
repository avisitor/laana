<?php

class DomParser {
    /**
     * Converts an HTML string into a DOMDocument object.
     *
     * @param string $html The HTML string to parse.
     * @return DOMDocument|null The parsed DOM document, or null on failure.
     */
    public function parse($html) {
        if (empty($html)) {
            return null;
        }

        $dom = new DOMDocument();
        $dom->encoding = 'utf-8';

        // Suppress errors for malformed HTML, as we'll handle them.
        libxml_use_internal_errors(true);

        // Ensure the HTML is properly encoded and has a basic structure.
        $html = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0xFFFFFF], 'UTF-8');

        $prefix = "";
        if (strpos($html, "<!DOCTYPE ") === false) {
            $prefix = '<!DOCTYPE html>';
            if (strpos($html, "<html") === false) {
                $prefix .= '<html>';
            }
            $html = $prefix . $html;
            if (strpos($html, "</html") === false) {
                $html .= "</html>";
            }
            //$text = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $text . '</body></html>';
        }
        
        $dom->loadHTML($html);
        libxml_clear_errors(); // Clear any stored errors.

        return $dom;
    }
}
