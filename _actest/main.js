import { createApp, ref, h } from 'vue'
import { createVuetify } from 'vuetify'
import * as components from 'vuetify/components'
import * as directives from 'vuetify/directives'
import 'vuetify/styles'
const vuetify = createVuetify({ components, directives })
const log = []
function mk(Comp, name) {
  return {
    setup() {
      const items = ref([{ id: 7, label: '292 Bremsbelagsatz', description: 'Bremsbelagsatz', partnumber: '292' }])
      return () => h(Comp, {
        modelValue: 'Alte Beschreibung',
        items: items.value, noFilter: true, customFilter: () => true,
        itemTitle: 'label', itemValue: 'id', returnObject: true, menu: true,
        'onUpdate:modelValue': v => { log.push(name + ':model=' + (v && typeof v === 'object' ? 'OBJ('+v.description+')' : 'STR('+JSON.stringify(v)+')')) },
      })
    }
  }
}
const App = {
  setup() { return () => h(components.VApp, [h(mk(components.VCombobox,'combo')), h('div',{id:'result'},'')]) },
  mounted() {
    setTimeout(() => {
      const input = document.querySelector('input')
      input.focus()
      input.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }))
      setTimeout(() => {
        input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }))
        // zusätzlich versuchen: Listeneintrag klicken
        const li = document.querySelector('.v-list-item'); if (li) li.click()
        setTimeout(() => { document.getElementById('result').textContent = 'LOG=[ ' + log.join(' ; ') + ' ]' }, 250)
      }, 250)
    }, 600)
  }
}
createApp(App).use(vuetify).mount('#app')
