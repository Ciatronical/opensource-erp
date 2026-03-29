SELECT json_build_object(
    'success', true,

   'permissions', json_build_array('read', 'write'),
   
    'payload',
        json_build_object(
            'cv',
                (
                    SELECT row_to_json(cv)
                    FROM (
                        SELECT
                            'C' AS src,
                            id,
                            customernumber AS cvnumber,
                            greeting,
                            name,
                            street,
                            zipcode,
                            contact,
                            phone AS phone1,
                            fax AS phone2,
                            phone3,
                            note_phone AS note_phone1,
                            note_fax AS note_phone2,
                            note_phone3,
                            email,
                            city,
                            country
                        FROM customer
                        WHERE id = 69738
                    ) AS cv
                ),

            'off',
                (
                    SELECT json_agg(obj ORDER BY itime DESC)
                    FROM (
                        SELECT
                            oe.itime,
                            json_build_object(
                                'date', to_char(oe.transdate, 'DD.MM.YYYY'),
                                'description', firstpos.description,
                                'amount', COALESCE(ROUND(oe.amount, 2)) || ' ' || COALESCE(c.name),
                                'number', oe.quonumber,
                                'id', oe.id
                            ) AS obj
                        FROM oe
                        LEFT JOIN currencies c ON c.id = oe.currency_id
                        LEFT JOIN LATERAL (
                            SELECT oi.description, oi.position
                            FROM orderitems oi
                            WHERE oi.trans_id = oe.id
                            ORDER BY oi.position
                            LIMIT 1
                        ) AS firstpos ON TRUE
                        WHERE oe.quotation = TRUE
                          AND customer_id = 69738
                    ) AS t
                ),

            'ord',
                (
                    SELECT json_agg(obj ORDER BY itime DESC)
                    FROM (
                        SELECT
                            oe.itime,
                            json_build_object(
                                'date', to_char(oe.transdate, 'DD.MM.YYYY'),
                                'description', firstpos.description,
                                'amount', COALESCE(ROUND(oe.amount, 2)) || ' ' || COALESCE(c.name),
                                'number', oe.ordnumber,
                                'id', oe.id
                            ) AS obj
                        FROM oe
                        LEFT JOIN currencies c ON c.id = oe.currency_id
                        LEFT JOIN LATERAL (
                            SELECT x.description, x.position
                            FROM (
                                SELECT i.description, i.position
                                FROM instructions i
                                WHERE i.trans_id = oe.id

                                UNION ALL

                                SELECT oi.description, oi.position
                                FROM orderitems oi
                                WHERE oi.trans_id = oe.id
                            ) x
                            ORDER BY x.position
                            LIMIT 1
                        ) AS firstpos ON TRUE
                        WHERE oe.quotation = FALSE
                          AND customer_id = 69738
                          AND EXISTS (
                              SELECT 1
                              FROM information_schema.tables
                              WHERE table_name = 'lxc_ver'
                          )
                    ) AS t
                ),

            'del',
                (
                    SELECT json_agg(obj ORDER BY do_id DESC)
                    FROM (
                        SELECT
                            delivery_orders.id AS do_id,
                            json_build_object(
                                'id', delivery_orders.id,
                                'date', to_char(delivery_orders.transdate, 'DD.MM.YYYY'),
                                'description', firstpos.description,
                                'deldate', to_char(delivery_orders.reqdate, 'DD.MM.YYYY'),
                                'donumber', delivery_orders.donumber
                            ) AS obj
                        FROM delivery_orders
                        LEFT JOIN LATERAL (
                            SELECT doi.description, doi.position
                            FROM delivery_order_items doi
                            WHERE doi.delivery_order_id = delivery_orders.id
                            ORDER BY doi.position
                            LIMIT 1
                        ) AS firstpos ON TRUE
                        WHERE customer_id = 69738
                          AND closed = FALSE
                    ) AS t
                ),

            'inv',
                (
                    SELECT json_agg(obj ORDER BY hdr_id DESC)
                    FROM (
                        SELECT
                            ar.id AS hdr_id,
                            json_build_object(
                                'date', to_char(ar.transdate, 'DD.MM.YYYY'),
                                'description', COALESCE(firstpos.description, '---------'),
                                'amount', COALESCE(ROUND(ar.amount, 2)) || ' ' || COALESCE(c.name),
                                'number', ar.invnumber,
                                'id', ar.id
                            ) AS obj
                        FROM ar
                        LEFT JOIN currencies c ON c.id = ar.currency_id
                        LEFT JOIN LATERAL (
                            SELECT i.description, i.position
                            FROM invoice i
                            WHERE i.trans_id = ar.id
                            ORDER BY i.position
                            LIMIT 1
                        ) AS firstpos ON TRUE
                        WHERE customer_id = 69738
                    ) AS t
                ),

            'custom_vars',
                (
                    SELECT json_agg(custom_vars)
                    FROM (
                        SELECT
                            COALESCE(
                                custom_variables.bool_value::text,
                                custom_variables.number_value::text,
                                custom_variables.timestamp_value::text,
                                custom_variables.text_value
                            ) AS value,
                            custom_variable_configs.description
                        FROM custom_variables
                        JOIN custom_variable_configs
                          ON custom_variables.config_id = custom_variable_configs.id
                        WHERE trans_id = 69738
                    ) AS custom_vars
                ),

            'contacts',
                (
                    SELECT json_agg(contacts)
                    FROM (
                        SELECT *
                        FROM contacts
                        WHERE cp_cv_id = 69738
                    ) AS contacts
                ),

            'cars',
                (
                    SELECT json_agg(cars)
                    FROM (
                        SELECT
                            c_id,
                            c_ln,
                            COALESCE(hersteller, '---------') AS hersteller,
                            COALESCE(name, '---------')       AS name,
                            COALESCE(fhzart, '---------')     AS mytype
                        FROM lxc_cars
                        LEFT JOIN lxckba ON (lxc_cars.kba_id = lxckba.id)
                        WHERE c_ow = 69738
                        ORDER BY c_id DESC
                    ) AS cars
                ),

            'emp_name',
                (
                    SELECT json_agg(emp_name)
                    FROM (
                        SELECT name AS emp_name
                        FROM employee
                        WHERE id = '861'
                    ) AS emp
                )
        )
) AS result;


----------------------
Ergebnis
----------------------

{
  "success": true,
  "permissions": ["read", "write"],
  "payload": {
    "cv": {
      "src": "C",
      "id": 69738,
      "cvnumber": "10226",
      "greeting": "Frau",
      "name": "Antje Nielens",
      "street": "Erich Weinert Weg 5",
      "zipcode": "15366",
      "contact": null,
      "phone1": "01749184990",
      "phone2": null,
      "phone3": null,
      "note_phone1": null,
      "note_phone2": null,
      "note_phone3": null,
      "email": null,
      "city": "Neuenhagen",
      "country": "D"
    },
    "off": [
      {
        "date": "22.04.2024",
        "description": "Bremsbelagsatz HA Erstausrüster-Qualität ",
        "amount": "799.58 EUR",
        "number": "210683",
        "id": 91641
      }
    ],
    "ord": [
      {
        "date": "22.05.2025",
        "description": "Stoßdämpfer VA rechts",
        "amount": "456.72 EUR",
        "number": "215067",
        "id": 98169
      },
      {
        "date": "15.10.2024",
        "description": "Räderwechsel Saison",
        "amount": "79.46 EUR",
        "number": "215066",
        "id": 98148
      },
      {
        "date": "22.04.2024",
        "description": "Service, Liquide, Wischer, Licht- und Wischwaschanlage prüfen, Uhr stellen",
        "amount": "551.27 EUR",
        "number": "214126",
        "id": 91533
      },
      {
        "date": "18.10.2023",
        "description": "Räderwechsel Saison mit Einlagerung",
        "amount": "59.38 EUR",
        "number": "213321",
        "id": 86296
      },
      {
        "date": "27.04.2023",
        "description": "WD durchführen",
        "amount": "369.31 EUR",
        "number": "212638",
        "id": 81113
      },
      {
        "date": "04.04.2022",
        "description": "Wartungsdienst nach Bedarf durchführen",
        "amount": "259.02 EUR",
        "number": "211453",
        "id": 69739
      }
    ],
    "del": null,
    "inv": [
      {
        "date": "15.10.2024",
        "description": "Räderwechsel PKW",
        "amount": "79.46 EUR",
        "number": "215513",
        "id": 14528
      },
      {
        "date": "22.04.2024",
        "description": "Motorenölfilter",
        "amount": "551.27 EUR",
        "number": "214523",
        "id": 12095
      },
      {
        "date": "18.10.2023",
        "description": "Räderwechsel + Einlagerung für 4 Räder PKW",
        "amount": "59.38 EUR",
        "number": "213708",
        "id": 10130
      },
      {
        "date": "28.04.2023",
        "description": "Hauptuntersuchung DEKRA inkl. Steuern",
        "amount": "92.00 EUR",
        "number": "212979",
        "id": 8887
      },
      {
        "date": "28.04.2023",
        "description": "Motorenölfilter",
        "amount": "277.31 EUR",
        "number": "212978",
        "id": 8886
      },
      {
        "date": "04.04.2022",
        "description": "Motorenölfilter",
        "amount": "249.63 EUR",
        "number": "211547",
        "id": 5379
      }
    ],
    "custom_vars": [
      {"value": "", "description": "Onlineshop"},
      {"value": "", "description": "Kundennummer"},
      {"value": "", "description": "passwd"},
      {"value": "", "description": "Öffnungszeit"},
      {"value": "", "description": "Versicherung"},
      {"value": "", "description": "Geburtstag"},
      {"value": "", "description": "Trinkgeld"},
      {"value": "", "description": "Verhalten"},
      {"value": "", "description": "Benutzername"}
    ],
    "contacts": null,
    "cars": [
      {
        "c_id": 5078,
        "c_ln": "MOL-AN288",
        "hersteller": "SKODA (CZ)",
        "name": "FABIA",
        "mytype": "car"
      }
    ],
    "emp_name": ["Ronny Zimmermann"]
  }
}