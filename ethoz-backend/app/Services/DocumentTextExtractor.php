<?php

namespace App\Services;

use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser as PdfParser;

class DocumentTextExtractor
{
    public function extract(string $path, string $ext): string
    {
        return match (strtolower($ext)) {
            'pdf' => $this->pdf($path),
            'docx' => $this->docx($path),
            'txt', 'md' => (string) file_get_contents($path),
            default => '',
        };
    }

    protected function pdf(string $path): string
    {
        return trim((new PdfParser)->parseFile($path)->getText());
    }

    protected function docx(string $path): string
    {
        $doc = IOFactory::load($path);
        $out = [];
        foreach ($doc->getSections() as $section) {
            $this->walk($section->getElements(), $out);
        }

        return trim(implode("\n", $out));
    }

    protected function walk($elements, array &$out): void
    {
        foreach ($elements as $el) {
            if (method_exists($el, 'getText')) {
                $t = $el->getText();
                if (is_string($t) && $t !== '') {
                    $out[] = $t;
                }
            }
            if (method_exists($el, 'getElements')) {
                $this->walk($el->getElements(), $out);
            }
        }
    }
}
