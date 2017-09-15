# Что это?

Генератор sitemap`ов для произвольного сайта.

# Особенности реализации

Все настройки подключения к БД указаны в файле `db.ini`. В репозитории есть пример этого файла: `db.ini.example`.

Предполагается, что конфиг-файлы недоступны посторонним, но я все равно рекомендую создать для генерации сайтмэпа отдельного
пользователя исключительно с правом SELECT (и, разумеется, прописать в `db.ini` соответствующие значения):

```
CREATE USER 'sitemapcreator'@'localhost' IDENTIFIED BY 'sitemappassword';
GRANT SELECT ON database.* TO `sitemapcreator`@`localhost`;
FLUSH PRIVELEGES;
```

Инструкции по построению сайтмапов для разных типов страниц задаются в файле `sitemap.ini`. Каждый тип страницы описывается в отдельной секции.
Информация о страницах может извлекаться как из БД (MySQL), так и из текстового файла.

Как писать этот конфиг?

## Глобальные настройки
Они задаются в секции с именем `___GLOBAL_SETTINGS___`

```
[___GLOBAL_SETTINGS___]
; URL сайта (домен, включая финальный слэш)
sitehref = 'http://www.example.com/'

; где лежат файлы сайтмапов (разумеется, мы должны иметь права на запись в этот каталог)
; на данный момент "корневой" сайтмэп сохраняется в эту же папку
sitemaps_storage = '/var/www/example.com/sitemaps/'

; URL (включая домен и финальный слеш) к промежуточным файлам сайтмапов
sitemaps_href = 'http://www.example.com/sitemaps/'

; максимальное количество URL в файле sitemap
limit_urls = 50000

; максимальный размер промежуточного файла sitemap без сжатия
limit_bytes = 50000000

; использовать ли gzip для сжатия
use_gzip = 1

; выводить ли информацию о генерации файлов карт?
logging = 1
```

## Пример секции: данные о страницах берутся из БД

```
; название секции
[price]
; корень имени файла, содержащего ссылки для страниц данной категории
; файлы будут иметь вид price-1, price-2 etc
; опцию можно опустить или оставить пустой - тогда имя файла будет таким же, как имя секции
radical = 'price'

; источник данных - sql, file, csv
source = 'sql'

; запрос к БД для получения количества элементов, для которых строим sitemap
sql_count_request = 'SELECT COUNT(id) AS cnt FROM price'

; поле в результатах запроса, содержащее нужное количество
sql_count_value = 'cnt'

; запрос к БД для получения всех нужных нам элементов (LIMIT ... OFFSET ... не указывать!)
sql_data_request = 'SELECT id, lastmod FROM price'

; имя поля в результатах запроса, содержащее айди страницы
sql_data_id = 'id'

; имя поля в результатах запроса, содержащее дату последней модификации 
; страницы. Используется для атрибута lastmod в sitemap-ссылке.
sql_data_lastmod = 'lastmod'

; URL до страницы от корня сайта (исключая домен)
; используется как маска для sprintf
url_location = 'price/%s.html'

; приоритет страниц в секции
url_priority = '0.5'

; вероятная частота обновления страниц в этой секции
; допустимые значения:
; always, hourly, daily, weekly, monthly, yearly, never
url_changefreq = 'daily'
```

### Важный момент №1 - Необычные запросы

запрос может быть и другим, к примеру
```
SELECT CONCAT('/page/xxx/', id) AS it, FORMAT_DATE(mask, lastdate) AS pld FROM price
```
тогда имена полей с данными должны быть `it` и `pld` соотв.

### Важный момент №2 - Условные операторы в запросе

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

### Важный момент №3 - sort order

```
sql_data_request = 'SELECT id, lastmod FROM price ORDER BY lastmod DESC'
```
Это допустимая строчка. Но лучше тоже через **VIEW**.

## Пример секции: данные о страницах берутся из текстового файла

```
; страны-регионы
[countries]
; корень имени файла, содержащего ссылки для страниц данной категории
; файлы будут иметь вид countries-1, countries-2 etc (см. separator в секции $GLOBAL_SETTINGS$)
radical = 'countries'

; источник данных
source = 'file'

; имя файла с данными построчно
filename = 'countries.txt'

; URL до страницы от корня сайта (исключая домен)
; используется маска для sprintf
url_location = 'countries/%s/'

; приоритет страниц в секции
url_priority = '0.5'

; вероятная частота обновления страниц в этой секции.
; допустимые значения:
; always, hourly, daily, weekly, monthly, yearly, never
url_changefreq = 'daily'

; единственное допустимое значение. Означает, что для lastmod ссылки берется текущий таймштамп
lastmod = 'NOW()'
```

## Todo
- источник данных - CSV с именованными столбцами
- определиться, нужна ли `use_gzip` как локальная опция (вдобавок к глобальной), перекрывающая глобальное значение
- определиться, как задавать lastmod для страниц/строк, описанных в файлах (как источниках данных). Сейчас NOW() означает текущий таймштамп.
- На данный момент "корневой" сайтмэп сохраняется в ту же папку, что и сайтмапы секций. Надо ли задавать иное поведение?


# Ссылки

- [Спецификация Sitemap](https://www.sitemaps.org/ru/protocol.html)
- [библиотека XMLWriter](http://php.net/manual/ru/book.xmlwriter.php) (на Gentoo требуется компиляция с флагом `+xmlwriter`

