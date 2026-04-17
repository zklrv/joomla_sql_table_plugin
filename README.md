# Joomla 5 PostgreSQL AJAX Report (mod_pg_report + pgreportengine)

Репозиторій містить 2 розширення Joomla 5:

- `modules/mod_pg_report` — модуль UI (AJAX таблиця, пошук, сортування, пагінація).
- `plugins/system/pgreportengine` — системний плагін-движок виконання SQL і агрегацій.

## Встановлення (folder-based install)

1. Увімкніть режим встановлення з директорії в Extension Manager Joomla.
2. Встановіть плагін із папки:
   - `plugins/system/pgreportengine`
3. Встановіть модуль із папки:
   - `modules/mod_pg_report`
4. Увімкніть плагін **System - PostgreSQL Report Engine**.
5. Створіть та опублікуйте модуль **PostgreSQL Report (AJAX)**.

## Налаштування модуля

У модулі задаються всі параметри (окремо для кожного інстансу модуля):

- PostgreSQL: `host`, `port`, `dbname`, `user`, `password`, `schema` (опц.), `sslmode`, `sslrootcert` (опц.)
- SQL: повний базовий `SELECT`
- Group cascade (CSV): типово `department_name,maindepartament,dept_code,dept_id`
- Search columns (CSV)
- ACL: `allow_guests`, `acl_mode (allow/deny)`, `acl_groups`

## Безпека SQL

Движок виконує перевірки:

- тільки один `SELECT`
- заборонено `;`
- блокуються DDL/DML ключові слова (`INSERT/UPDATE/DELETE/ALTER/DROP/TRUNCATE/COPY/CREATE/...`)
- опційно видаляється кінцевий `ORDER BY` з базового SQL

## API (com_ajax)

Модульний AJAX endpoint:

`index.php?option=com_ajax&module=pg_report&method=query&format=json`

JS (`modules/mod_pg_report/media/js/report.js`) викликає endpoint через `fetch` із Joomla CSRF token.

## Приклад базового SQL (додано `employee_id`)

```sql
SELECT 
    oes.id AS oes_id,
    oe.id AS employee_id,
    od.parentid AS dept_parent_id,
    od.id AS dept_id,
    od.code AS dept_code,
    od1.fullname AS maindepartament,
    od.fullname AS department_name,
    os.fullname AS staff_unit_name,
    oe.fullfio,
    oes.employeeonstafftype,
    os.caption AS staff_caption,
    oe.birthdate,
    oe.mi_datefrom,
    oe.sextype,
    c_email.value AS email,
    c_mobile.value AS mobile_phone,
    c_ip.value AS ip_phone
FROM org_department od
LEFT JOIN org_department od1 ON od.parentid = od1.id
LEFT JOIN org_staffunit os ON os.parentid = od.id AND os.mi_deleteuser IS NULL
LEFT JOIN org_employeeonstaff oes ON oes.staffunitid = os.id 
    AND oes.mi_deleteuser IS NULL 
    AND oes.mi_dateto >= CURRENT_DATE
LEFT JOIN org_employee oe ON oe.id = oes.employeeid
LEFT JOIN cdn_contact c_email ON c_email.subjectid = oe.id 
    AND c_email.mi_deleteuser IS NULL 
    AND c_email.contacttypeid = 3000000001275
LEFT JOIN cdn_contact c_mobile ON c_mobile.subjectid = oe.id 
    AND c_mobile.mi_deleteuser IS NULL 
    AND c_mobile.contacttypeid = 3000000001279
LEFT JOIN cdn_contact c_ip ON c_ip.subjectid = oe.id 
    AND c_ip.mi_deleteuser IS NULL 
    AND c_ip.contacttypeid = 3000000001278
LEFT JOIN uba_user u ON oe.userid = u.id
    AND u.disabled = 0
    AND u.name LIKE 'ukrcapital%'
WHERE 
    od.mi_deleteuser IS NULL
    AND oes.employeeonstafftype = 'PERMANENT'
```

## Підрахунки по групах

- `employees_cnt = COUNT(DISTINCT employee_id)`
- `positions_cnt = COUNT(DISTINCT oes_id)`

Якщо `employee_id` не знайдено, модуль повертає попередження.
