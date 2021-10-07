<?php

declare(strict_types=1);

/*
 * This file is part of Export Table for Contao CMS.
 *
 * (c) Marko Cupic 2021 <m.cupic@gmx.ch>
 * @license GPL-3.0-or-later
 * For the full copyright and license information,
 * please view the LICENSE file that was distributed with this source code.
 * @link https://github.com/markocupic/export_table
 */

namespace Markocupic\ExportTable\Export;

use Contao\Backend;
use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Database;
use Contao\System;
use Markocupic\ExportTable\Config\Config;
use Markocupic\ExportTable\Helper\Str;
use Markocupic\ExportTable\Writer\CsvWriter;
use Markocupic\ExportTable\Writer\XmlWriter;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class ExportTable.
 */
class ExportTable extends Backend
{
    /**
     * @var string
     */
    private $projectDir;

    /**
     * @var ContaoFramework
     */
    private $framework;

    /**
     * @var
     */
    private $requestStack;

    /**
     * @var Str
     */
    private $str;

    /**
     * @var CsvWriter
     */
    private $csvWriter;

    /**
     * @var XmlWriter
     */
    private $xmlWriter;

    /**
     * @var string
     */
    private $strTable;

    /**
     * @var array
     */
    private $arrData = [];

    /**
     * ExportTable constructor.
     */
    public function __construct(string $projectDir, ContaoFramework $framework, RequestStack $requestStack, Str $str, CsvWriter $csvWriter, XmlWriter $xmlWriter)
    {
        $this->projectDir = $projectDir;
        $this->framework = $framework;
        $this->requestStack = $requestStack;
        $this->str = $str;
        $this->csvWriter = $csvWriter;
        $this->xmlWriter = $xmlWriter;
    }

    /**
     * @throws \Exception
     */
    public function exportTable(Config $objConfig): void
    {
        $this->strTable = $objConfig->getTable();

        $databaseAdapter = $this->framework->getAdapter(Database::class);
        $controllerAdapter = $this->framework->getAdapter(Controller::class);
        $systemAdapter = $this->framework->getAdapter(System::class);

        // Load Datacontainer
        $controllerAdapter->loadDataContainer($this->strTable, true);
        $arrDca = $GLOBALS['TL_DCA'][$this->strTable] ?? [];

        // If no fields are chosen, then list all fields from the selected table
        $arrSelectedFields = $objConfig->getFields();
        if (empty($arrSelectedFields)) {
            $arrSelectedFields = $databaseAdapter->getInstance()->getFieldNames($this->strTable);
        }

        // Load language for the headline fields
        if (!empty($objConfig->getHeadlineLabelLang())) {
            $controllerAdapter->loadLanguageFile($this->strTable, $objConfig->getHeadlineLabelLang());
        }

        $arrHeadline = [];

        foreach ($arrSelectedFields as $strFieldname) {
            $arrHeadline[] = $arrDca[$strFieldname][0] ?? $strFieldname;
        }

        // Add the headline first to data array
        $this->arrData[] = $arrHeadline;

        // Handle filter expression
        // Get filter as JSON encoded arrays -> [["tablename.field=? OR tablename.field=?"],["valueA","valueB"]]
        $arrFilterStmt = $this->getFilterStmt($objConfig->getFilter(), $objConfig);
        $strSortingStmt = $this->getSortingStmt($objConfig->getSortBy(), $objConfig->getSortDirection());

        $objDb = $databaseAdapter->getInstance()
            ->prepare('SELECT * FROM  '.$this->strTable.' WHERE '.$arrFilterStmt['stmt'].' ORDER BY '.$strSortingStmt)
            ->execute(...$arrFilterStmt['values'])
        ;

        while ($arrDataRecord = $objDb->fetchAssoc()) {
            $arrRow = [];

            foreach ($arrSelectedFields as $strFieldname) {
                $varValue = $arrDataRecord[$strFieldname];

                // HOOK: Process data with your custom hooks
                if (isset($GLOBALS['TL_HOOKS']['exportTable']) && \is_array($GLOBALS['TL_HOOKS']['exportTable'])) {
                    foreach ($GLOBALS['TL_HOOKS']['exportTable'] as $callback) {
                        $objCallback = $systemAdapter->importStatic($callback[0]);
                        $varValue = $objCallback->{$callback[1]}($strFieldname, $varValue, $this->strTable, $arrDataRecord, $arrDca, $objConfig);
                    }
                }

                $arrRow[] = $varValue;
            }
            $this->arrData[] = $arrRow;
        }

        // xml-output
        if ('xml' === $objConfig->getExportType()) {
            $this->xmlWriter->write($this->arrData, $objConfig);
        }

        // csv-output
        if ('csv' === $objConfig->getExportType()) {
            $this->csvWriter->write($this->arrData, $objConfig);
        }
    }

    private function getFilterStmt(array $arrFilter, Config $objConfig): array
    {
        $request = $this->requestStack->getCurrentRequest();

        $strFilter = json_encode($arrFilter);

        if ($objConfig->getActivateDeepLinkExport()) {
            // Replace {{GET::*}} with the value of a GET parameter --> ...?firstname=James&lastname=Bond
            // [["firstname={{GET::firstname}} AND lastname={{GET::lastname}}]]
            if (preg_match_all('/{{GET::(.*)}}/', $strFilter, $matches)) {
                foreach (array_keys($matches) as $k) {
                    if ($matches[1][$k] && $request->query->has($matches[1][$k])) {
                        $strReplace = $request->query->get($matches[1][$k]);
                        $strFilter = str_replace($matches[0][$k], $strReplace, $strFilter);
                    }
                }
            }
        }

        // Sanitize $strFilter from {{GET::*}}
        $strFilter = preg_replace('/{{GET::(.*)}}/', 'empty-string', $strFilter);

        // Replace insert tags
        $controllerAdapter = $this->framework->getAdapter(Controller::class);
        $strFilter = $controllerAdapter->replaceInsertTags($strFilter);

        $arrFilter = json_decode($strFilter);

        // Default filter statement
        $filterStmt = $this->strTable.'.id>?';
        $arrValues = [0];

        if (!empty($arrFilter)) {
            if (2 === \count($arrFilter)) {
                // Statement
                if (\is_array($arrFilter[0])) {
                    $filterStmt .= ' AND '.implode(' AND ', $arrFilter[0]);
                } else {
                    $filterStmt .= ' AND '.$arrFilter[0];
                }

                // Values
                if (\is_array($arrFilter[1])) {
                    foreach ($arrFilter[1] as $v) {
                        $arrValues[] = $v;
                    }
                } else {
                    $arrValues[] = $arrFilter[1];
                }
            }
        }

        // Check for invalid strings
        if ($this->str->containsInvalidChars(strtolower($filterStmt.' '.$arrValues), $objConfig->getInvalidFilterExpr())) {
            $message = sprintf(
                'Illegal filter statements detected. Do not use "%s" in your filter expression.',
                implode(', ', $objConfig->getInvalidFilterExpr()),
            );

            throw new \Exception($message);
        }

        return ['stmt' => $filterStmt, 'values' => $arrValues];
    }

    private function getSortingStmt(string $strFieldname = 'id', string $direction = 'desc'): string
    {
        $arrSorting = [$strFieldname, $direction];

        return implode(' ', $arrSorting);
    }
}
