![Alt text](docs/logo.png?raw=true "logo")

# Export Table für Contao CMS

Mit dieser Erweiterung lassen sich aus dem Contao Backend heraus Datenbank-Tabellen ins CSV- oder XML-Format exportieren. Dabei kann der Export konfiguriert werden.
- Tabelle auswählbar
- Felder auswählbar
- Über die Eingabe eines json-Arrays Datensätze filtern
- Ausgabe sortierbar (Feldname und Richtung)
- Delimiter einstellbar (Default: ;)
- Enclosure einstellbar (Default: ")
- Trennzeichen für Arrays einstellbar
- Deeplink Support

![Alt text](docs/backend.png?raw=true "Backend")

## Der Einsatz von Filtern
Der Export ist über Filter konfigurierbar.

Folgender einfacher Filter für die Mitgliedertabelle *tl_member* lässt nur **Frauen** aus **Luzern** zu:\
`[["gender=? AND city=?"],["female","Luzern"]]`

Oder nur **Frauen** aus **Luzern** oder **Bern**:\
`[["gender=? AND (city=? OR city=?)"],["female","Luzern", "Bern"]]`

Auch der Gebrauch von Contao Insert Tags ist möglich:\
`[["lastname=? AND city=?"],["{{user::lastname}}","Oberkirch"]]`

Oder Parameterübergabe aus der URL:\
`[["lastname=? AND city=?"],["{{GET::lastname}}","Oberkirch"]]`

## Für Entwickler: Die Ausgabe über den "exportTable" HOOK steuern

Via Hook kann die Ausgabe angepasst werden. Die Erweiterung selber nutzt diese Hooks. Beispielsweise werden timestamps zu formatierten Daten umgewandelt. Bereits vorhandene Hooks lassen sich über einen eigenen Hook deaktivieren. Dabei muss die Priority so eingestellt werden, dass der neue Hook vor dem bestehenden aufgerufen wird.\
Siehe dieses Beispiel:

```php
// App/eventListener/ExportTable/FormatDateListener.php

declare(strict_types=1);

namespace App\EventListener\ExportTable;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\ServiceAnnotation\Hook;
use Contao\Date;
use Markocupic\ExportTable\Config\Config;
use Markocupic\ExportTable\Listener\ContaoHooks\ExportTableFormatDateListener;
use Markocupic\ExportTable\Listener\ContaoHooks\ExportTableListenerInterface;

/**
 * @Hook(MyCustomFormatDateListener::HOOK, priority=MyCustomFormatDateListener::PRIORITY)
 */
class MyCustomFormatDateListener implements ExportTableListenerInterface
{
    public const HOOK = 'exportTable';
    public const PRIORITY = 100;

    /**
     * @var bool
     */
    private static $disableHook = false;

    /**
     * @var ContaoFramework
     */
    private $framework;

    public function __construct(ContaoFramework $framework)
    {
        $this->framework = $framework;
    }

    /**
     * @param $varValue
     *
     * @return mixed
     */
    public function __invoke(string $strFieldname, $varValue, string $strTablename, array $arrDataRecord, array $arrDca, Config $objConfig)
    {
        if (static::$disableHook) {
            return false;
        }

        // Disable original Hook that is shipped with the export table extension.
        ExportTableFormatDateListener::disableHook();

        $dateAdapter = $this->framework->getAdapter(Date::class);

        $dca = $arrDca['fields'][$strFieldname] ?? null;

        if ($dca) {
            $strRgxp = $dca['eval']['rgxp'];

            if ('' !== $varValue && $strRgxp && \in_array($strRgxp, ['date', 'datim', 'time'], true)) {
                $dateFormat = $dateAdapter->getFormatFromRgxp($strRgxp);
                $varValue = $dateAdapter->parse($dateFormat, $varValue);
            }
        }

        return $varValue;
    }

    public static function disableHook(): void
    {
        self::$disableHook = true;
    }

    public static function enableHook(): void
    {
        self::$disableHook = false;
    }
}


```


## ExportTable aus eigenem Controller heraus nutzen
Die ExportTable-Klasse lässt sich recht simpel auch aus anderen Erweiterungen heraus nutzen.

Dazu muss als Erstes der Export konfiguriert werden. Als Konstruktor-Argument wird der Konfigurationsklasse der Name der zu exportierenden Tabelle übergeben. Mit dieser Minimalkonfiguration werden die Default-Einstellungen übernommen. Ein Beispiel mit einer etwas ausführlicheren Konfiguration findest du weiter unten.

```
$config = new Config('tl_member');
```
Der eigentliche Export-Service wird mit der Methode `$this->exportTable->run($objConfig)` aufgerufen, welche als einzigen Parameter das vorher erstellte Config-Objekt erwartet.
```
$config = new Config('tl_member');

return $this->exportTable->run($config);
```

Hier ein etwas ausführlicheres Beispiel eingebettet in einem Custom Controller.

```php
// App/Controller/CustomController.php

declare(strict_types=1);

namespace App\Controller;

use Contao\CoreBundle\Exception\ResponseException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Markocupic\ExportTable\Config\Config;
use Markocupic\ExportTable\Export\ExportTable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/_test_export", name="_test_export", defaults={"_scope" = "frontend", "_token_check" = false})
 */
class CustomController extends AbstractController
{
    /**
     * @var ContaoFramework
     */
    private $framework;

    /**
     * @var ExportTable
     */
    private $exportTable;

    public function __construct(ContaoFramework $framework, ExportTable $exportTable)
    {
        $this->framework = $framework;
        $this->exportTable = $exportTable;
    }

    /**
     * @throws \Exception
     */
    public function __invoke(): Response
    {
        $this->framework->initialize();

        // First you have to config your data export.
        $config = (new Config('tl_member'))
            ->setExportType('csv')
            ->setFields(['firstname', 'lastname', 'dateOfBirth'])
            ->setDelimiter(',')
            ->setEnclosure('"')
            // Select * FROM tl_member WHERE tl_member.city = 'Oberkirch'
            ->setFilter([["city=?"],["Oberkirch"]])
            // Define a target path, otherwise the file will be stored in system/tmp
            ->setTargetFolder('files')
            // Define a filename, otherwise the file will become the name of the table ->tl_member.csv
            ->setFilename('export.csv')
            // Use a row callback to convert from utf-8 to ISO-8859-1
            ->setRowCallback(
                static function ($arrRow) {
                    return array_map(function($varValue){
                        return is_string($varValue) ? iconv("UTF-8", "ISO-8859-1", $varValue) : $varValue;
                    }, $arrRow);
                }
            )
        ;

        // The export class takes the config object as single parameter.
        return $this->exportTable->run($config);
    }
}

```


Viel Spass mit Export Table!
