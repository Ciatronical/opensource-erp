function raf2(fn) {
  requestAnimationFrame(() => requestAnimationFrame(fn))
}

function makeMeasurer(input) {
  const canvas = document.createElement('canvas')
  const ctx = canvas.getContext('2d')
  return function measure(text) {
    const cs = window.getComputedStyle(input)
    ctx.font =
      cs.font ||
      `${cs.fontStyle} ${cs.fontVariant} ${cs.fontWeight} ${cs.fontSize} / ${cs.lineHeight} ${cs.fontFamily}`
    return ctx.measureText(text).width
  }
}

function caretIndexFromClientX(input, clientX, measure) {
  const rect = input.getBoundingClientRect()
  const cs = window.getComputedStyle(input)

  const paddingLeft = parseFloat(cs.paddingLeft) || 0
  const paddingRight = parseFloat(cs.paddingRight) || 0

  const x = clientX - rect.left - paddingLeft + input.scrollLeft
  const value = input.value ?? ''
  const len = value.length

  const textAreaWidth = rect.width - paddingLeft - paddingRight
  if (x <= 0) return 0
  if (x >= textAreaWidth + input.scrollLeft) return len

  let lo = 0
  let hi = len
  while (lo < hi) {
    const mid = Math.floor((lo + hi) / 2)
    const w = measure(value.slice(0, mid))
    if (w < x) lo = mid + 1
    else hi = mid
  }

  const i1 = Math.max(0, lo - 1)
  const i2 = Math.min(len, lo)

  const w1 = measure(value.slice(0, i1))
  const w2 = measure(value.slice(0, i2))

  return Math.abs(x - w1) <= Math.abs(w2 - x) ? i1 : i2
}

export default {
  mounted(el) {
    let active = false
    let downX = null
    let downY = null
    let downShift = false
    let lastClickTime = 0

    const DOUBLE_CLICK_MS = 300
    const DRAG_PX = 4

    const getInput = () => el.querySelector('input')

    const onPointerDown = (e) => {
      const input = getInput()
      if (!input) return

      active = true
      downX = e.clientX
      downY = e.clientY
      downShift = !!e.shiftKey

      input.focus({ preventScroll: true })
    }

    const onPointerUp = (e) => {
      if (!active) return
      active = false

      const input = getInput()
      if (!input) return

      // Shift+Click: Browser/Input soll Range erweitern -> nicht eingreifen
      if (downShift || e.shiftKey) return

      const upX = e.clientX
      const upY = e.clientY

      // Drag-Selection -> nicht eingreifen
      if (downX != null && downY != null) {
        const dx = Math.abs(upX - downX)
        const dy = Math.abs(upY - downY)
        if (dx > DRAG_PX || dy > DRAG_PX) return
      }

      // Double click -> nicht eingreifen (Wort/Alles markieren bleibt)
      const now = Date.now()
      const isDouble = now - lastClickTime < DOUBLE_CLICK_MS
      lastClickTime = now
      if (isDouble) return

      // Normaler Single-Click -> Caret aus Mausposition berechnen und setzen
      const measure = makeMeasurer(input)
      const idx = caretIndexFromClientX(input, upX, measure)

      raf2(() => {
        try {
          input.setSelectionRange(idx, idx) // hebt ggf. bestehende Markierung auf
        } catch (_) {}
      })
    }

    el.__preserveCursorHandlers = { onPointerDown, onPointerUp }
    el.addEventListener('pointerdown', onPointerDown, true)
    el.addEventListener('pointerup', onPointerUp, true)

    // Fallback
    el.addEventListener('mousedown', onPointerDown, true)
    el.addEventListener('mouseup', onPointerUp, true)
  },

  unmounted(el) {
    const h = el.__preserveCursorHandlers
    if (!h) return
    el.removeEventListener('pointerdown', h.onPointerDown, true)
    el.removeEventListener('pointerup', h.onPointerUp, true)
    el.removeEventListener('mousedown', h.onPointerDown, true)
    el.removeEventListener('mouseup', h.onPointerUp, true)
    delete el.__preserveCursorHandlers
  },
}
