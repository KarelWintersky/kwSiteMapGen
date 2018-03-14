# Что это?

Генератор sitemap`ов для произвольного сайта.

# Установка и настройка

Для работы скрипта нам потребуется как минимум файл конфигурации.
В файле конфигурации могут быть упомянуты несколько статических файлов (plain/text или text/csv) с данными.

В папке `production` есть пример необходимого набора файлов:
- `config.sitemap+db.example.ini` - пример конфигурационного файла
- `data.countries.txt` - текстовый файл со списком страниц для стран мира
- `data.staticpages.txt` - текстовый файл со списком статических страниц
- `sitemap_generator.php` - готовый скрипт для генерации карты сайта.

## Установка

Все исходники лежат в каталоге `/sources` и собираются в `sitemap_generator.php` при помощи build-скрипта.

```
/bin/bash ./build.sitemap_generator.sh
```

Готовый файл можно положить куда-нибудь и запускать без указания пути к интерпретатору:
```
chmod +x ./production/sitemap_generator.php
mv ./production/sitemap_generator.php /usr/local/bin
sitemap_generator.php --help
```

## Запуск

Теперь мы можем запустить его из консоли, передав ему аргументом путь к файлу конфигурации:

```
/usr/local/bin/sitemap_generator.php /path/fo/my_sitemap.ini
```

Или, из текущего каталога:
```
/usr/local/bin/sitemap_generator.php my_sitemap.ini
```

## Настройка

Все настройки доступа к БД и инструкции по построению сайтмэпов задаются в ini-файлах. Предполагается, что конфиг-файлы недоступны
посторонним, но я все равно рекомендую создать для генерации сайтмэпа отдельного пользователя исключительно с правом SELECT
и использовать в файлах конфигурации эти значения.

```
CREATE USER 'sitemapcreator'@'localhost' IDENTIFIED BY 'sitemappassword';
GRANT SELECT ON database.* TO `sitemapcreator`@`localhost`;
FLUSH PRIVELEGES;
```

Файл конфигурации состоит из как минимум трёх секций:
- секция глобальных настроек
- секция настроек подключения к БД
- одна или несколько секций, описывающих, как строить сайтмап для страниц определенной категории

Опишу эти опции последовательно:

### Секция глобальных настроек

Это обязательная секция, без неё просто непонятно что делать.

```
[___GLOBAL_SETTINGS___]
; [ОБЯЗАТЕЛЬНАЯ ОПЦИЯ]
; URL сайта (домен, включая финальный слэш)
sitehref = 'http://www.example.com/'

; [ОБЯЗАТЕЛЬНАЯ ОПЦИЯ]
; URL (включая домен и финальный слеш) к промежуточным файлам сайтмапов
sitemaps_href = 'http://www.example.com/sitemaps/'

; [ОБЯЗАТЕЛЬНАЯ ОПЦИЯ]
; Директория, куда записываются файлы сайтмапов (разумеется, мы должны иметь права на запись в этот каталог)
sitemaps_storage = '/var/www/example.com/sitemaps/'

; [ОБЯЗАТЕЛЬНАЯ ОПЦИЯ]
; путь и имя файла к основному (индексному) файлу сайтмэпа. Если указать только имя - файл сохранится в текущий каталог.
; разумеется, скрипт должен иметь права на задпись этого файла по указанному пути
sitemaps_mainindex = 'sitemap.xml'

; [НЕОБЯЗАТЕЛЬНАЯ ОПЦИЯ]
; максимальное количество URL в файле sitemap, по умолчанию 50000
limit_urls = 50000

; [НЕОБЯЗАТЕЛЬНАЯ ОПЦИЯ]
; максимальный размер промежуточного файла sitemap без сжатия, по умолчанию 50000000 байт
; на самом деле по стандарту максимум 50 мегабайт, это немного больше 50 млн. байт, но из-за особенностей реализации
; рекомендуется указывать именно такое значение
limit_bytes = 50000000

; [НЕОБЯЗАТЕЛЬНАЯ ОПЦИЯ]
; использовать ли gzip для сжатия, по умолчанию TRUE
; это глобальная настройка, её можно перекрыть в секции
use_gzip = 1

; [НЕОБЯЗАТЕЛЬНАЯ ОПЦИЯ]
; выводить ли информацию о генерации файлов карт? По умолчанию: выводить.
logging = 1

; формат представления даты. Допустимые значения:
;   iso8601 - дата в формате W3C Datetime (2004-12-23T18:00:15+00:00)
; * YMD - дата в формате Y-m-d (2004-12-23), этот формат используется по умолчанию (если опция не задана или содержит иное значение)
date_format_type = 'iso8601'

; [ОБЯЗАТЕЛЬНАЯ ОПЦИЯ]
; суффикс для секции с настройками подключения
; Пустое значение возможно только в том случае, если ВСЕ секции берут данные из файлов. Но все равно будет выведено предупреждение.
db_section_suffix = 'DATABASE'
```

### Секция настроек подключения к БД

Имя этой секции: `___GLOBAL_SETTINGS:<db_section_suffix>___`. Эту секцию можно опустить, но ТОЛЬКО в том случае
если все остальные секции берут данные из файлов.

```
[___GLOBAL_SETTINGS:DATABASE___]
; драйвер, в данный момент используется только значение mysql
driver   = 'mysql'

; hostname для подключения (обычно localhost)
hostname = ''

; имя пользователя для подключения к БД (помните, я советовал создать отдельного пользователя?)
username = ''

; пароль для подключения к БД
password = ''

; имя БД с нужными данными
database = ''

; порт (для MySQL по умолчанию 3306), указывать обязательно
port     = 3306
```

### Секции настроек сайтмапа для страниц определенной категории

Данные для построения сайтмапа могут браться из трёх источников:
- БД
- text file
- CSV-file (в разработке)

В зависимости от источника данных описание секции будет различным:

#### Пример секции: данные о страницах берутся из БД

```
; название секции
[price]
; [ОБЯЗАТЕЛЬНАЯ ОПЦИЯ]
; источник данных - sql, file, csv
source = 'sql'

; [ОБЯЗАТЕЛЬНАЯ ОПЦИЯ]
; запрос к БД для получения количества элементов, для которых строим sitemap
sql_count_request = 'SELECT COUNT(id) AS cnt FROM price'

; [ОБЯЗАТЕЛЬНАЯ ОПЦИЯ]
; поле в результатах запроса, содержащее нужное количество
sql_count_value = 'cnt'

; [ОБЯЗАТЕЛЬНАЯ ОПЦИЯ]
; запрос к БД для получения всех нужных нам элементов (LIMIT ... OFFSET ... не указывать!)
sql_data_request = 'SELECT id, lastmod FROM price'

; [ОБЯЗАТЕЛЬНАЯ ОПЦИЯ]
; имя поля в результатах запроса, содержащее айди страницы
sql_data_id = 'id'

; [ОБЯЗАТЕЛЬНАЯ ОПЦИЯ]
; имя поля в результатах запроса, содержащее дату последней модификации
; страницы. Используется для атрибута lastmod в sitemap-ссылке.
; ВАЖНО: если этого поля в таблице нет в принципе - следует использовать значение 'NOW()', то есть текущий момент времени (таймштамп).
sql_data_lastmod = 'lastmod'

; [ОБЯЗАТЕЛЬНАЯ ОПЦИЯ]
; URL до страницы от корня сайта (исключая домен)
; используется как маска для sprintf
url_location = 'price/%s.html'

; [РЕКОМЕНДУЕМАЯ ОПЦИЯ]
; приоритет страниц в секции, по умолчанию 0.5
url_priority = '0.5'

; [РЕКОМЕНДУЕМАЯ ОПЦИЯ]
; вероятная частота обновления страниц в этой секции
; допустимые значения: always, hourly, daily, weekly, monthly, yearly, never
; значение по умолчанию: never
url_changefreq = 'daily'

; [НЕОБЯЗАТЕЛЬНАЯ ОПЦИЯ]
; корень имени файла, содержащего ссылки для страниц данной категории
; файлы будут иметь вид price-1, price-2 etc
; опцию можно опустить или оставить пустой - тогда имя файла будет таким же, как имя секции
radical = 'price'

; [НЕОБЯЗАТЕЛЬНАЯ ОПЦИЯ]
; использовать ли gzip-сжатие для файлов sitemap для данной секции. Перекрывает глобальное значение use_gzip
use_gzip = 0
```

##### Важный момент №1 - Необычные запросы

запрос может быть и другим, к примеру
```
SELECT CONCAT('/page/xxx/', id) AS it, FORMAT_DATE(mask, lastdate) AS pld FROM price
```
тогда имена полей с данными должны быть `it` и `pld` соотв.

##### Важный момент №2 - Условные операторы в запросе

Хотя задать условия выборки можно в опциях
```
sql_count_request = 'SELECT COUNT(id) AS cnt FROM price WHERE price.actual = 1'
sql_data_request = 'SELECT id, lastmod FROM price WHERE price.actual = 1'
```

...эффективнее будет использование **представления** (VIEW), к примеру:
```
CREATE VIEW actual_price
AS SELECT id, lastmod FROM price WHERE price.actual = 1;
```

Соответственно опции будут такими:
```
sql_count_request = 'SELECT COUNT(id) AS cnt FROM actual_price'
sql_data_request = 'SELECT id, lastmod FROM actual_price'
```

##### Важный момент №3 - sort order

```
sql_data_request = 'SELECT id, lastmod FROM price ORDER BY lastmod DESC'
```
Это допустимая строчка. Но лучше тоже через **VIEW**.

#### Пример секции: данные о страницах берутся из текстового файла

```
; страны-регионы
[countries]
; [ОБЯЗАТЕЛЬНАЯ ОПЦИЯ]
; источник данных
source = 'file'

; [ОБЯЗАТЕЛЬНАЯ ОПЦИЯ]
; путь к файлу с данными (хранимыми построчно)
; ВАЖНО: символ '$' означает, что файл ищется в том же каталоге, в котором лежит и файл конфигурации.
; В противном случае мы обязаны указать абсолютный путь к файлу (впрочем, можно сказать $/static/countries.txt)
filename = '$/countries.txt'

; [ОБЯЗАТЕЛЬНАЯ ОПЦИЯ]
; URL до страницы от корня сайта (исключая домен)
; используется маска для sprintf
url_location = 'countries/%s/'

; [РЕКОМЕНДУЕМАЯ ОПЦИЯ]
; приоритет страниц в секции, по умолчанию 0.5
url_priority = '0.5'

; [РЕКОМЕНДУЕМАЯ ОПЦИЯ]
; вероятная частота обновления страниц в этой секции
; допустимые значения: always, hourly, daily, weekly, monthly, yearly, never
; значение по умолчанию: never
url_changefreq = 'daily'

; единственное допустимое значение. Означает, что для lastmod ссылки берется текущий таймштамп
lastmod = 'NOW()'

; [НЕОБЯЗАТЕЛЬНАЯ ОПЦИЯ]
; корень имени файла, содержащего ссылки для страниц данной категории
; файлы будут иметь вид countries-1, countries-2 etc (см. separator в секции $GLOBAL_SETTINGS$)
radical = 'countries'
```

Пустая строчка в текстовом файле-источнике означает, очевидно, пустую строку. Так, в примере конфигурации описана секция [static], в которой
определен как источник данных файл `data.staticpages.txt`. Пустая строчка в этом файле означает, что необходимо сгенерировать ссылку на корневую
страницу сайта.

#### Пример секции: данные о страницах берутся из CSV-файла

@todo: в разработке


#### Примечание

Можно в отладочных целях запретить обработку какой-то секции. Для этого надо указать в теле секции опцию:
```
enabled = 0
```
Любое другое значение или отсутствие этой строчки означает необходимость обработки секции.

# Todo
- Добавить источник данных: CSV с именованными столбцами.
- Определиться, как задавать lastmod для страниц/строк, описанных в файлах (как источниках данных). Сейчас NOW() означает текущий таймштамп.
- На данный момент "корневой" сайтмэп сохраняется в ту же папку, что и сайтмапы секций. Надо ли задавать иное поведение?
- На данный момент если в обёртку `SitemapFileSaver::push()` не передаётся дата последней модификации - используется текущий таймштамп. Надо ли менять это поведение?
- Определиться, нужно ли разрешить использовать индивидуальный `date_format_type` для каждой секции?

# Скорость работы

На моём домашнем сервере (Gentoo/4.14.15 на GA-N3150N-D3V, CPU Celeron™ N3150 (1.6 GHz), PHP 7.1.13, HDD WD Blue 5400rpm in RAID1)
для тестовой БД с ~800 тыс. записей файлы сайтмэпа создаются около 45 секунд.

При этом пиковое потребление памяти составляет ~80 Мб.

# Генерация тестовых данных

В каталоге `/tests` лежат два файла:
- `make_db_seed.ini.example` - копируем его в `make_db_seed.ini` и задаем правильные параметры подключения к БД.
- `make_seed.php` - запускаем. Генерируются таблицы. Следует помнить, что старые таблицы удаляются.

# Ссылки

- [Спецификация Sitemap](https://www.sitemaps.org/ru/protocol.html)
- [библиотека XMLWriter](http://php.net/manual/ru/book.xmlwriter.php) (на Gentoo требуется компиляция с флагом `+xmlwriter`
- [W3C Datetime Specification](https://www.w3.org/TR/NOTE-datetime)

# Совместимость

Требуется PHP 7.0 и выше.

# Лицензия

Используйте где хотите и как хотите, но уведомите автора (т.е. меня). Форки, пулл-реквесты и переводы приветствуются.