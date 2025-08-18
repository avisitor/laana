<?php

class TextProcessor {
    private $placeholder = '[[DOT]]';
    private static $abbreviations = [
        'Mr.', 'Mrs.', 'Ms.', 'Dr.', 'DR.', 'Prof.', 'Sr.', 'Jr.',
        'St.', 'Mt.', 'Ave.', 'H.K.Aloha', 'Lt.', 'Col.', 'Gen.',
        'Capt.', 'Sgt.', 'Rev.', 'Hon.', 'Pres.', 'Gov.', 'Sen.',
        'Rep.', 'Adm.', 'Maj.', 'Ph.D.', 'M.D.', 'D.D.S.', 'B.A.',
        'M.A.', 'D.C.', 'U.S.', 'A.M.', 'P.M.', 'Inc.', 'Ltd.',
        'Co.', 'No.', 'Dept.', 'Univ.', 'etc.', 'Vol.', 'Wm.',
        'Robt.', 'Geo.', 'GEO.', 'JNO.',
    ];

    public function cleanSentence($sentence) {
        $sentence = str_replace($this->placeholder, '.', $sentence);
        $sentence = preg_replace('/(\s+)(\?|\!)/', '$2', $sentence);
        return $sentence;
    }

    public function convertEncoding($text) {
        if (!is_string($text)) {
            $text = (string)$text;
        }
        $pairs = [
            '&Auml;&#129;' => "ā", "&Auml;&#128;" => "Ā", '&Ecirc;&raquo;' => '‘',
            '&Aring;&#141;' => 'ū', '&Auml;&ordf;' => 'Ī', '&Auml;&#147;' => 'ē',
            '&raquo;' => '', '&laquo;' => '', '&mdash;' => '-', '&nbsp;' => ' ',
            "&Ecirc;" => '‘', "&Aring;" => 'ū', '&Atilde;&#133;&Acirc;&#140;' => 'Ō',
            '&Atilde;&#133;&Acirc;' => 'ū', '&Atilde;&#132;&Acirc;' => 'ā',
            '&Atilde;&#138;&Acirc;&raquo;' => '‘', "&Acirc;" => '', "&acirc;" => '',
            '&lsquo;' => "'", '&rsquo;' => "'", '&rdquo;' => '"', '&ldquo;' => '"',
            "&auml;" => "ā", "&Auml;" => "Ā", "&Euml;" => "Ē", "&euml;" => "ē",
            "&Iuml;" => "Ī", "&iuml;" => "ī", "&ouml;" => "ō", "&Ouml;" => "Ō",
            "&Uuml" => "Ū", "&uuml;" => "ū", "&aelig;" => '‘', "&#128;&brvbar;" => '-',
            "&#128;&#147;" => '-', "&#128;&#148;" => '-', "&#128;&#152;" => '‘',
            "&#128;&#156;" => '"', "&#128;&#157;" => '-', '&#157;&#x9D;' => '-',
            "&#128;" => "", "&#129;" => "", "&#140;" => "Ō", "&#146;" => "'",
            "&#256;" => "Ā", "&#257;" => "ā", "&#274;" => "Ē", "&#275;" => "ē",
            "&#298;" => "Ī", "&#299;" => "ī", "&#332;" => "Ō", "&#333;" => "ō",
            "&#362;" => "Ū", "&#363;" => "ū", "&#699;" => '‘',
        ];
        $text = strtr($text, $pairs);
        $replace = [
            '/\x80\x99/u' => ' ', '/\x80\x9C/u' => ' ', '/\x80\x9D/u' => ' ',
            '/&nbsp;/' => ' ', '/"/' => '',
            '/[\x{0080}\x{00A6}\x{009C}\x{0099}]/u' => '.',
        ];
        $text = preg_replace(array_keys($replace), array_values($replace), $text);
        $text = trim($text);
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function protectAbbreviations($text) {
        $placeholder = $this->placeholder;
        $abbrPattern = implode('|', array_map(function($abbr) {
            return preg_quote($abbr, '/');
        }, self::$abbreviations));
        $text = preg_replace_callback('/\b(' . $abbrPattern . ')/', function($matches) use ($placeholder) {
            return str_replace('.', $placeholder, $matches[1]);
        }, $text);
        $text = preg_replace_callback('/\b([A-Z](?:\.[A-Z]){1,}\.)/', function($matches) use ($placeholder) {
            return str_replace('.', $placeholder, $matches[1]);
        }, $text);
        $text = preg_replace_callback('/\b([A-Z])\.(?=\s)/', function($matches) use ($placeholder) {
            return $matches[1] . $placeholder;
        }, $text);
        $text = preg_replace_callback('/\b([A-Z][a-z]{1,2})\./', function($matches) use ($placeholder) {
            return $matches[1] . $placeholder;
        }, $text);
        $text = preg_replace_callback('/(\d[\d\s\-]*\.)\s*(?=\d)/', function($matches) use ($placeholder) {
            return rtrim($matches[1], '.') . $placeholder;
        }, $text);
        return $text;
    }

    public function splitLines($text) {
        $pattern = '/(?<=[.?!])\s+(?=(?![‘ʻ\x{2018}\x{02BB}])[A-ZāĀĒēĪīōŌŪū])/u';
        $lines = preg_split($pattern, $text, -1, PREG_SPLIT_NO_EMPTY);
        if (count($lines) < 2) {
            $lines = preg_split('/\,+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        }
        return $lines;
    }
}
