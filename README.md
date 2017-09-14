Все настройки подключения к БД указаны в файле `db.ini`. Предполагается, что конфиг-файлы недоступны посторонним,
но я все равно рекомендую создать для генерации сайтмэпа отдельного пользователя исключительно с правом SELECT:

```
CREATE USER 'sitemapcreator'@'localhost' IDENTIFIED BY 'sitemappassword';
GRANT SELECT ON database.* TO `sitemapcreator`@`localhost`;
FLUSH PRIVELEGES;
```

В файле `sitemap.ini` задаются инструкции по построению сайтмапов по типам страниц. Каждый тип страницы описывается в отдельной секции. Данные по типу могут извлекаться как из БД, так и из статического файла-источника.

Как писать этот конфиг?

## Глобальные настройки
Они задаются в секции с именем ___

```
[___]
; URL сайта (домен, включая финальный слэш)
sitehref = 'http://www.example.com/'

; где лежат файлы сайтмапов (разумеется, мы должны иметь права на запись туда)
sitemaps_storage = '/var/www/example.com/sitemaps/'

; URL (включая домен и финальный слеш) к промежуточным файлам сайтмапов
sitemaps_href = 'http://www.example.com/sitemaps/'

; максимальное количество URL в файле sitemap
limit_urls = 50000

; максимальный размер промежуточного файла sitemap без сжатия
limit_bytes = 50000000

; использовать ли gzip для сжатия
use_gzip = 1
```

## Секция, берущая данные из БД

```
[price]
; корень имени файла, содержащего ссылки для страниц данной категории
; файлы будут иметь вид price-1, price-2 etc
; опцию можно опустить или оставить пустым - тогда оно будет 
; установлено в имя секции
radical = 'price'

; источник данных - sql или file
source = 'sql'

; запрос к БД для получения количества элементов, для которых строим sitemap
sql_count_request = 'SELECT COUNT(id) AS cnt FROM price'

; поле в результатах запроса, содержащее нужное количество
sql_count_value = 'cnt'

; запрос к БД для получения всех нужных нам элементов (исключая LIMIT OFFSET)
sql_data_request = 'SELECT id, lastmod FROM price'

; имя поля в результатах запроса, содержащее айди страницы
sql_data_id = 'id'

; имя поля в результатах запроса, содержащее дату последней модификации 
; страницы. Используется для атрибута lastmod в файле карты.
sql_data_lastmod = 'lastmod'

; URL до страницы от корня сайта (исключая домен)
; используется маска для sprintf
url_location = 'price/%s.html'

; приоритет страниц в секции
url_priority = '0.5'

; вероятная частота обновления страниц в этой секции
; допустимые значения:
; always, hourly, daily, weekly, monthly, yearly, never
url_changefreq = 'daily'
```

#### ВАЖНЫЙ МОМЕНТ №1 - Необычные запросы

запрос может быть и другим, к примеру
```
SELECT CONCAT('/page/xxx/', id) AS it, FORMAT_DATE(mask, lastdate) AS pld FROM price
```
тогда имена полей с данными должны быть it и pld соотв.

#### ВАЖНЫЙ МОМЕНТ №2 - Условные операторы в запросе

Хотя задать условия выборки можно в опциях
```
sql_count_request = 'SELECT COUNT(id) AS cnt FROM price WHERE price.actual = 1'
sql_data_request = 'SELECT id, lastmod FROM price WHERE price.actual = 1'
```

Эффективнее использовать **представление** (VIEW), к примеру:
```
CREATE VIEW actual_price
AS SELECT id, lastmod FROM price WHERE price.actual = 1;
```
Опции будут такими:
```
sql_count_request = 'SELECT COUNT(id) AS cnt FROM actual_price'
sql_data_request = 'SELECT id, lastmod FROM actual_price'
```

## Секция, берущая данные из статического файла

```
; страны-регионы
[countries]
; корень имени файла, содержащего ссылки для страниц данной категории
; файлы будут иметь вид countries-1, countries-2 etc
radical = 'countries'

; источник данных
source = 'file'

; имя файла с данными построчно. 
filename = 'countries.txt'

; URL до страницы от корня сайта (исключая домен)
; используется маска для sprintf
url_location = 'countries/%s/'

; приоритет страниц в секции
url_priority = '0.5'

; вероятная частота обновления страниц в этой секции
; допустимые значения:
; always, hourly, daily, weekly, monthly, yearly, never
url_changefreq = 'daily'

; единственное допустимое значение. Означает, что для lastmod ссылки\
; берется текущий таймштамп
lastmod = 'NOW()'
```

## Проблемы

Генерация элементов для сайтмапа занимает безумно много времени и затраты времени растут нелинейно:
Количество строк - секунд на набор
10000   -   10
1000    -   0.1
2000    -   0.2
3000    -   0.45 .. 0.55
4000    -   0.8 .. 0.9
5000    -   1.35 .. 1.45
Налицо удвоение времени за каждую 1000 строк.

Предлагаемое решение:
а) проверить, виновата ли библиотека XMLWriter или обёртка
См. файл fetch2.php : вывод неутешителен - виновата обёртка. Без неё 50000 строк генерируются от 4.5 до 5 секунд.
Причем узкое место - проверка длины буфера:
```
count($this->xmlw->flush(false))
или
count($this->xmlw->outputMemory(false))
```
Не имеет значения, какая функция выбрана

Затраты времени:
100к строк кусками по 5000:
Затраты времени на проверку инстанса XMLWriter: 0.1
Затраты времени на проверку длины буфера: 18.92

100к строк кусками по 10000:
Затраты времени на проверку инстанса XMLWriter: 0.14
Затраты времени на проверку длины буфера: 82.96

100к кусками по 7500:
Затраты времени на проверку инстанса XMLWriter: 0.12
Затраты времени на проверку длины буфера: 62.17

Варианты решения:
а) после генерации каждого элемента в XMLWriter делать flush буфера и хранить его в отдельной переменной (возможно(!) это будет быстрее)
б) отказаться от XMLWriter`а вообще и генерировать XML самописной "библиотекой"

## Todo
- источник данных - CSV с именованными столбцами
- определиться, нужна ли `use_gzip` как локальная опция (вдобавок к глобальной), перекрывающая глобальное значение
- определиться, как задавать lastmod для страниц/строк, описанных в файлах (как источниках данных). Сейчас NOW() означает текущий таймштамп.

----

## Генерация тестовых данных

Шаблон таблицы генерируется при помощи хранимой процедуры:
```
DELIMITER $$
DROP PROCEDURE IF EXISTS `generate_data`$$

CREATE PROCEDURE `generate_data`(IN quantity INT)
BEGIN
  DECLARE i INT DEFAULT 0;
  TRUNCATE TABLE __template;

  WHILE i < quantity DO
    INSERT INTO __template (`lastmod`,`subject`) VALUES (
      FROM_UNIXTIME(UNIX_TIMESTAMP('2014-01-01 01:00:00')+FLOOR(RAND()*31536000)),
      ROUND(RAND()*100,2)
    );
    SET i = i + 1;
  END WHILE;
END$$

DELIMITER ;
```
Генерируется таблица, которую мы потом дублируем под нужным именем.