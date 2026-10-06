#!/bin/bash
# Demo-Vorlagensatz in den Ausgangszustand: Designs löschen, Handvorlagen zurückholen
set -e
cd /home/work/opensource-erp/backend/templates
rm -rf demo-editor && cp -r schoenert demo-editor && rm -f demo-editor/*.bak-*
PGPASSWORD='IagaM.' psql -h localhost -U postgres -d ap_dev -Atc "delete from print_template_designs where template_set = 'templates/demo-editor'" >/dev/null
echo reset-ok
