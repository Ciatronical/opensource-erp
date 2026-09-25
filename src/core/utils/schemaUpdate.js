// core/utils/schemaUpdate.js
//
// Hinweis auf ein fälliges Datenbank-Update während der Arbeit.
//
// Bei der Anmeldung und beim Firmenwechsel wird das Update schon selbst
// ausgeführt. Wer aber angemeldet bleibt, während neue Upstall-Dateien
// eingespielt werden, bekommt nur Fehler "Spalte/Tabelle fehlt". Scheitert
// eine Anfrage daran, fragt diese Datei das Backend, ob die Prüfsummen
// abweichen. Wenn ja, bekommt der Benutzer einen Hinweis mit Button und
// entscheidet selbst, ob das Update läuft — auch dann, wenn es immer wieder
// scheitert. Die fehlgeschlagene Aktion wird nicht wiederholt: sie hat
// womöglich schon Teile geschrieben.

import axios from 'axios'
import Swal from 'sweetalert2'
import i18n from '@/i18n'
import * as alerts from './alerts.js'

const { t } = i18n.global

const UPDATE_URL = '/api/update/'

// PG-SQLSTATE: 42703 = undefined column, 42P01 = undefined table
const SCHEMA_FEHLER = ['SQLSTATE[42703]', 'SQLSTATE[42P01]']

let offen = false

/**
 * Scheiterte die Antwort an einer fehlenden Spalte oder Tabelle?
 *
 * Der Code steht in `text`, der SQL-Fehler je nach Einstieg in `payload`,
 * `debug` oder hinter dem Code in `text`.
 */
export function isSchemaMismatch(antwort) {
    if (!antwort || antwort.success !== false) return false
    if (!String(antwort.text || '').startsWith('API_DATABASE_ERROR')) return false
    return [antwort.text, antwort.payload, antwort.debug]
        .some(wert => typeof wert === 'string' && SCHEMA_FEHLER.some(zustand => wert.includes(zustand)))
}

/**
 * Prüft die Prüfsummen und bietet bei Abweichung das Update an
 *
 * Solange ein Hinweis offen ist, lösen weitere fehlgeschlagene Anfragen
 * keinen zweiten aus.
 */
export async function offerSchemaUpdate() {
    if (offen) return
    offen = true
    try {
        const pruefung = await axios.post(UPDATE_URL, { action: 'schemaUpdateNeeded' })
        if (!pruefung.data?.success || !pruefung.data.payload?.update_needed) return

        const frage = await alerts.warning(
            t('SchemaUpdate.text'),
            t('SchemaUpdate.title'),
            t('SchemaUpdate.confirm'),
            t('SchemaUpdate.cancel')
        )
        if (!frage.isConfirmed) return

        Swal.fire({
            title: t('SchemaUpdate.running'),
            text: t('LoginView.updateHint'),
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            didOpen: () => Swal.showLoading(),
        })

        // Ohne 'client' aktualisiert das Backend die Firma der Sitzung
        const lauf = await axios.post(UPDATE_URL, { action: 'updateSchema', dry_run: false })
        Swal.close()

        if (lauf.data?.success) {
            alerts.success(t('SchemaUpdate.success'))
            return
        }
        const einzelheiten = typeof lauf.data?.payload === 'string'
            ? lauf.data.payload
            : (lauf.data?.payload?.errors || []).join('\n')
        if (lauf.data?.text === 'SCHEMA_UPDATE_RUNNING') {
            alerts.error(t('SchemaUpdate.alreadyRunning'), t('SchemaUpdate.title'))
            return
        }
        const meldung = t('SchemaUpdate.failed')
        alerts.error(einzelheiten ? `${meldung}\n\n${einzelheiten}` : meldung, t('SchemaUpdate.title'))
    } catch (e) {
        Swal.close()
        console.error('Schema-Update:', e)
    } finally {
        offen = false
    }
}
