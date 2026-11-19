// src/core/views/camera/camera.labels.js
// Farbe und Symbol je erkanntem Objekttyp — gemeinsam für Ereignisliste und
// Aufzeichnungen. Datenfarben, daher fest (siehe layout/README.md).

const colors = {
    person: 'red', car: 'blue', dog: 'orange', cat: 'purple',
    motorcycle: 'teal', bicycle: 'cyan', truck: 'indigo',
    bird: 'lime', horse: 'brown',
}

const icons = {
    person: 'mdi-account', car: 'mdi-car', dog: 'mdi-dog',
    cat: 'mdi-cat', motorcycle: 'mdi-motorbike', bicycle: 'mdi-bicycle',
    truck: 'mdi-truck', bus: 'mdi-bus', bird: 'mdi-bird', horse: 'mdi-horse',
}

export function labelColor(label) {
    return colors[label] || 'grey'
}

export function labelIcon(label) {
    return icons[label] || 'mdi-eye'
}
