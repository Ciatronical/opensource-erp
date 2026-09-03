// src/core/components/calendar/oserp-fullcalendar.js
//
// Gemeinsame FullCalendar-v7-Basis für alle OSERP-Kalender (Termine,
// Wiedervorlagen, Aufgaben, Wandanzeige).
//
// FullCalendar 7 liefert kein CSS mehr mit und kennt keine .fc-*-Klassen.
// Das Aussehen wird über ein Theme-Plugin plus "Class-Hooks" gesteuert:
// Jede Hook-Option hängt eigene Klassen an die jeweiligen DOM-Elemente,
// die Klassen des Classic-Themes bleiben dabei erhalten (Merge, kein
// Überschreiben). Die OSERP-Optik dazu steht in oserp-fullcalendar.css.
import classicThemePlugin from '@fullcalendar/vue3/themes/classic'
import deLocale from '@fullcalendar/vue3/locales/de'

import '@fullcalendar/vue3/skeleton.css'
import '@fullcalendar/vue3/themes/classic/theme.css'
import '@fullcalendar/vue3/themes/classic/palette.css'
import './oserp-fullcalendar.css'

export { classicThemePlugin }

// FullCalendar-Locale zum vue-i18n-Sprachcode (undefined = englischer Default)
export function calendarLocale(code) {
    return code === 'de' ? deLocale : undefined
}

function join(...parts) {
    return parts.filter(Boolean).join(' ')
}

// Wochentag 0–6 aus den Hook-Infos. FullCalendar-Datumsmarker tragen die
// lokale Zeit in den UTC-Feldern, daher getUTCDay().
function dow(info) {
    return typeof info.dow === 'number' ? info.dow : info.date?.getUTCDay?.()
}

function dayClasses(base, info) {
    const d = dow(info)
    return join(
        base,
        info.isToday && `${base}--today`,
        info.isOther && `${base}--other`,
        (d === 0 || d === 6) && `${base}--weekend`,
        d !== undefined && `ofc-dow-${d}`
    )
}

// Class-Hooks des OSERP-Designs — in calendarOptions per Spread einbinden.
export const oserpCalendarHooks = {
    className: 'oserp-cal',

    // Toolbar
    toolbarClass: 'ofc-toolbar',
    toolbarSectionClass: 'ofc-toolbar-section',
    toolbarTitleClass: 'ofc-toolbar-title',
    buttonGroupClass: 'ofc-btn-group',
    buttonClass: (info) => join(
        'ofc-btn',
        info.isPrimary && 'ofc-btn--primary',
        info.isSelected && 'ofc-btn--active',
        info.isDisabled && 'ofc-btn--disabled',
        info.isIconOnly && 'ofc-btn--icon'
    ),

    // Ansicht / Tabelle
    viewClass: 'ofc-view',
    tableHeaderClass: 'ofc-table-header',
    dayHeaderClass: (info) => join(
        dayClasses('ofc-day-header', info),
        info.inPopover && 'ofc-popover-header'
    ),
    dayHeaderInnerClass: 'ofc-day-header-inner',

    // Monatsraster
    dayCellClass: (info) => dayClasses('ofc-day-cell', info),
    dayCellTopClass: 'ofc-day-top',
    dayCellTopInnerClass: 'ofc-day-number',
    dayRowClass: 'ofc-day-row',

    // Zeitraster
    dayLaneClass: (info) => dayClasses('ofc-day-lane', info),
    slotHeaderClass: 'ofc-slot-header',
    slotLaneClass: 'ofc-slot-lane',
    allDayHeaderClass: 'ofc-allday-header',
    nowIndicatorLineClass: 'ofc-now-line',
    nowIndicatorHeaderClass: 'ofc-now-arrow',
    nonBusinessHoursClass: 'ofc-non-business',

    // Termine
    eventClass: (info) => join('ofc-event', info.isSelected && 'ofc-event--selected'),
    eventInnerClass: 'ofc-event-inner',
    eventTimeClass: 'ofc-event-time',
    eventTitleClass: 'ofc-event-title',
    rowEventClass: 'ofc-row-event',
    columnEventClass: 'ofc-col-event',
    listItemEventClass: 'ofc-list-event',

    // Mehr-Link & Popover
    moreLinkClass: 'ofc-more-link',
    popoverClass: 'ofc-popover',
    popoverCloseClass: 'ofc-popover-close',

    // Listenansicht
    listDayHeaderClass: 'ofc-list-day-header',
    noEventsClass: 'ofc-no-events'
}
