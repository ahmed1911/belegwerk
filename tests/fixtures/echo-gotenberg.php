<?php

// Stands in for Gotenberg in tests: answers with "%PDF-" followed by the uploaded HTML files.
$html = '';
foreach ($_FILES as $file) {
    $html .= "<!-- {$file['name']} -->" . file_get_contents($file['tmp_name']);
}
echo '%PDF-' . $html;
