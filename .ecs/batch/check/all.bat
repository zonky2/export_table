:: Run easy-coding-standard (ecs) via this batch file inside your IDE e.g. PhpStorm (Windows only)
:: Install inside PhpStorm the  "Batch Script Support" plugin
cd..
cd..
cd..
cd..
cd..
cd..
:: src
vendor\bin\ecs check vendor/markocupic/export_table/src --config vendor/markocupic/export_table/.ecs/config/default.php
:: tests
vendor\bin\ecs check vendor/markocupic/export_table/tests --config vendor/markocupic/export_table/.ecs/config/default.php
:: legacy
vendor\bin\ecs check vendor/markocupic/export_table/src/Resources/contao --config vendor/markocupic/export_table/.ecs/config/legacy.php
:: templates
vendor\bin\ecs check vendor/markocupic/export_table/src/Resources/contao/templates --config vendor/markocupic/export_table/.ecs/config/template.php
::
cd vendor/markocupic/export_table/.ecs./batch/check