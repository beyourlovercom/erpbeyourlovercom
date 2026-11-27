<?php

namespace App\Modules\Logistics\Application;

use RuntimeException;
use XMLReader;
use ZipArchive;

/** Bounded read-only extraction of selected XLSX postal evidence cells; no formula evaluation. */
final class LogisticsRatePostalEvidenceReader
{
    private const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const PACKAGE_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    public function read(string $path, string $sheetName, array $columns, int $maxRow): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('邮编证据工作簿无效');
        }
        try {
            $workbook = $this->entry($zip, 'xl/workbook.xml', 1024 * 1024);
            $rels = $this->entry($zip, 'xl/_rels/workbook.xml.rels', 1024 * 1024);
            $book = simplexml_load_string($workbook, \SimpleXMLElement::class, LIBXML_NONET);
            $relations = simplexml_load_string($rels, \SimpleXMLElement::class, LIBXML_NONET);
            if (! $book || ! $relations) {
                throw new RuntimeException('邮编工作表索引无效');
            }
            $id = null;
            $book->registerXPathNamespace('m', self::MAIN_NS);
            foreach ($book->xpath('//m:sheet') as $sheet) {
                if ((string) $sheet['name'] === $sheetName) {
                    $id = (string) $sheet->attributes(self::REL_NS)['id'];
                    break;
                }
            }
            if (! $id) {
                throw new RuntimeException('邮编工作表索引缺失');
            }
            $target = null;
            $relations->registerXPathNamespace('p', self::PACKAGE_NS);
            foreach ($relations->xpath('//p:Relationship') as $rel) {
                if ((string) $rel['Id'] === $id && (string) $rel['TargetMode'] !== 'External') {
                    $raw = (string) $rel['Target'];
                    $target = str_starts_with($raw, '/') ? ltrim($raw, '/') : 'xl/'.$raw;
                    break;
                }
            }
            if (! $target || ! preg_match('~^xl/worksheets/sheet[0-9]+\.xml$~D', $target)) {
                throw new RuntimeException('不支持的邮编工作表路径');
            }
            $strings = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                $xml = $this->entry($zip, 'xl/sharedStrings.xml', 16 * 1024 * 1024);
                $reader = $this->xml($xml);
                try {
                    while ($reader->read()) {
                        if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                            if (count($strings) >= 300000) {
                                throw new RuntimeException('共享字符串超出读取上限');
                            }
                            $strings[] = $reader->readString();
                        }
                    }
                } finally {
                    $reader->close();
                }
                unset($xml);
            }
            $xml = $this->entry($zip, $target, 64 * 1024 * 1024);
            $reader = $this->xml($xml);
            $cells = [];
            $allowed = array_fill_keys($columns, true);
            try {
                while ($reader->read()) {
                    if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'c') {
                        continue;
                    }
                    $coord = $reader->getAttribute('r');
                    if (! $coord || ! preg_match('/^([A-Z]{1,3})([1-9][0-9]*)$/D', $coord, $m) || ! isset($allowed[$m[1]]) || (int) $m[2] > $maxRow || $reader->isEmptyElement) {
                        continue;
                    }
                    $type = $reader->getAttribute('t') ?? 'n';
                    $depth = $reader->depth;
                    $value = null;
                    $formula = false;
                    while ($reader->read()) {
                        if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth && $reader->localName === 'c') {
                            break;
                        }
                        if ($reader->nodeType !== XMLReader::ELEMENT) {
                            continue;
                        }
                        if ($reader->localName === 'f') {
                            $formula = true;
                        }
                        if ($reader->localName === 'v' || ($type === 'inlineStr' && $reader->localName === 't')) {
                            $part = $reader->readString();
                            $value = $type === 'inlineStr' ? ($value ?? '').$part : $part;
                        }
                    }
                    if ($formula || $value === null || $type === 'e') {
                        continue;
                    }
                    if ($type === 's') {
                        $value = ctype_digit($value) ? ($strings[(int) $value] ?? null) : null;
                    }
                    if ($value === null) {
                        continue;
                    }
                    $value = trim($value);
                    if ($value !== '') {
                        $cells[$coord] = $value;
                    }
                }
            } finally {
                $reader->close();
            }

            return $cells;
        } finally {
            $zip->close();
        }
    }

    private function entry(ZipArchive $zip, string $name, int $max): string
    {
        $stat = $zip->statName($name);
        if (! $stat || $stat['size'] > $max) {
            throw new RuntimeException('邮编文件部件超出安全上限或缺失');
        }
        $value = $zip->getFromName($name);
        if ($value === false || strlen($value) !== (int) $stat['size'] || stripos($value, '<!DOCTYPE') !== false) {
            throw new RuntimeException('邮编文件部件无效');
        }

        return $value;
    }

    private function xml(string $source): XMLReader
    {
        $reader = new XMLReader;
        if (! $reader->XML($source, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('无法读取邮编 XML');
        }
        $reader->setParserProperty(XMLReader::LOADDTD, false);
        $reader->setParserProperty(XMLReader::SUBST_ENTITIES,false);

        return $reader;
    }
}
