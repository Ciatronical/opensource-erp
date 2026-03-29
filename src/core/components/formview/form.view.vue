<template>
    <!--
  <v-form @submit.prevent="onSubmit" ref="formRef">
    -->
  <v-form ref="formRef">
    <v-container class="pa-0" fluid>
      <!-- Zwei Spalten -->
      <v-row>
        <v-col
          cols="12"
          md="6"
          lg="6"
          xl="5"
          v-for="(column, colIndex) in columnsConfig"
          :key="colIndex"
        >
          <v-container class="pa-0" fluid>
            <template
              v-for="(item, index) in column"
              :key="`${colIndex}-${index}-${item.name || 'headline'}`"
            >
              <!-- Headline als Card-Header -->
              <v-card
                v-if="item.type === 'headline'"
                class="mb-3"
                variant="outlined"
              >
                <v-card-title class="py-2 px-3 bg-grey-lighten-4">
                  <h4 class="text-subtitle-1 mb-0">
                    {{ $t(item.label) }}
                  </h4>
                </v-card-title>
                <v-divider />
                <v-card-text class="py-2 px-2 px-sm-3">
                  <v-row dense>
                    <!-- Felder der aktuellen Sektion -->
                    <template
                      v-for="(field, fieldIndex) in getFieldsForSection(column, index)"
                      :key="`field-${colIndex}-${index}-${fieldIndex}`"
                    >
                      <v-col cols="12" class="py-1" :class="{ 'field-highlight': field.highlight }">
                        <!-- Checkbox -->
                        <v-switch
                        v-if="field.type === 'checkbox'"
                        v-model="internalForm[field.name]"
                        :label="$t(field.label)"
                        density="compact"
                        hide-details="auto"
                        color="primary"
                        :hint="field.tooltip ? $t(field.tooltip) : undefined"
                        :persistent-hint="!!field.tooltip"
                        />
                        
                        <!-- Text Input -->
                        <v-text-field
                          v-else-if="field.type === 'input'"
                          v-model="internalForm[field.name]"
                          :label="$t(field.label)"
                          :hint="field.tooltip ? $t(field.tooltip) : undefined"
                          :persistent-hint="!!field.tooltip"
                          :maxlength="toNumber(field.size)"
                          density="compact"
                          variant="outlined"
                          hide-details="auto"
                          :style="field.fieldstyle || undefined"
                        />

                        <!-- Password -->
                        <v-text-field
                          v-else-if="field.type === 'password'"
                          v-model="internalForm[field.name]"
                          :type="showPassword[field.name] ? 'text' : 'password'"
                          :label="$t(field.label)"
                          :hint="field.tooltip ? $t(field.tooltip) : undefined"
                          :persistent-hint="!!field.tooltip"
                          :maxlength="toNumber(field.size)"
                          density="compact"
                          variant="outlined"
                          hide-details="auto"
                          :append-inner-icon="showPassword[field.name] ? 'mdi-eye-off' : 'mdi-eye'"
                          @click:append-inner="togglePassword(field.name)"
                          :style="field.fieldstyle || undefined"
                        />

                        <!-- Select -->
                        <v-select
                          v-else-if="field.type === 'select'"
                          v-model="internalForm[field.name]"
                          :items="field.data || []"
                          :label="$t(field.label)"
                          :hint="field.tooltip ? $t(field.tooltip) : undefined"
                          :persistent-hint="!!field.tooltip"
                          density="compact"
                          variant="outlined"
                          hide-details="auto"
                          :style="field.fieldstyle || undefined"
                        />

                        <!-- Fallback -->
                        <v-text-field
                          v-else
                          v-model="internalForm[field.name]"
                          :label="$t(field.label)"
                          :hint="field.tooltip ? $t(field.tooltip) : undefined"
                          :persistent-hint="!!field.tooltip"
                          density="compact"
                          variant="outlined"
                          hide-details="auto"
                          :style="field.fieldstyle || undefined"
                        />
                      </v-col>
                    </template>
                  </v-row>
                </v-card-text>
              </v-card>
            </template>
          </v-container>
        </v-col>
      </v-row>

      <!-- Buttons unten
      <v-row class="mt-4">
        <v-col cols="12" class="d-flex justify-start ga-2">
          <v-btn variant="text" @click="resetForm">
            {{ $t('common.reset') || 'Reset' }}
          </v-btn>
          <v-btn type="submit" color="primary">
            {{ $t('common.save') || 'Speichern' }}
          </v-btn>
        </v-col>
      </v-row>
       -->
    </v-container>
  </v-form>
</template>

<script>
import { reactive, watch, ref, computed } from 'vue'

export default {
  name: 'FormView',
  props: {
    modelValue: {
      type: Object,
      default: () => ({}),
    },
    config: {
      type: Array,
      required: true,
    },
  },
  emits: ['update:modelValue', 'submit'],
  setup(props, { emit }) {
    const formRef = ref(null)
    const internalForm = reactive({})
    const showPassword = reactive({})

    /**
     * Config in Sektionen aufteilen
     */
    const columnsConfig = computed(() => {
      const cfg = props.config || []
      if (cfg.length === 0) {
        return [[], []]
      }

      const sections = []
      let currentSection = []

      cfg.forEach((item) => {
        if (item.type === 'headline') {
          if (currentSection.length > 0) {
            sections.push(currentSection)
          }
          currentSection = [item]
        } else {
          if (currentSection.length === 0) {
            currentSection = [item]
          } else {
            currentSection.push(item)
          }
        }
      })

      if (currentSection.length > 0) {
        sections.push(currentSection)
      }

      const col1 = []
      const col2 = []

      sections.forEach((section, index) => {
        if (index % 2 === 0) {
          col1.push(...section)
        } else {
          col2.push(...section)
        }
      })

      return [col1, col2]
    })

    /**
     * Hilfsfunktion: Gibt alle Felder zurück, die zur aktuellen Sektion gehören
     */
    const getFieldsForSection = (column, headlineIndex) => {
      const fields = []
      for (let i = headlineIndex + 1; i < column.length; i++) {
        if (column[i].type === 'headline') {
          break
        }
        fields.push(column[i])
      }
      return fields
    }

    const initFromProps = () => {
      props.config.forEach((item) => {
        if (item.type === 'headline') return

        if (Object.prototype.hasOwnProperty.call(props.modelValue, item.name)) {
          internalForm[item.name] = props.modelValue[item.name]
        } else {
          internalForm[item.name] = item.type === 'checkbox' ? false : ''
        }

        if (item.type === 'password' && !Object.prototype.hasOwnProperty.call(showPassword, item.name)) {
          showPassword[item.name] = false
        }
      })
    }

    initFromProps()

    watch(
      () => props.config,
      () => initFromProps(),
      { deep: true }
    )

    //watch(
    //  internalForm,
    //  (val) => {
    //    emit('update:modelValue', { ...val })
    //  },
    //  { deep: true }
    //)

    let saveTimeout = null

    watch(
      internalForm,
      (val) => {
        emit('update:modelValue', { ...val })
        
        // Debounce: Warte 500ms nach der letzten Änderung
        if (saveTimeout) {
          clearTimeout(saveTimeout)
        }
        saveTimeout = setTimeout(() => {
          emit('submit', { ...val })
        }, 500)
      },
      { deep: true }
    )

    const togglePassword = (name) => {
      showPassword[name] = !showPassword[name]
    }

    const toNumber = (value) => {
      const n = Number(value)
      return Number.isNaN(n) ? undefined : n
    }

    //const resetForm = () => {
    //  initFromProps()
    //}

    //const onSubmit = async () => {
    //  if (formRef.value) {
    //    const { valid } = await formRef.value.validate()
    //    if (!valid) return
    //  }
    //  emit('submit', { ...internalForm })
    //}

    return {
      formRef,
      internalForm,
      showPassword,
      columnsConfig,
      getFieldsForSection,
      togglePassword,
      toNumber,
      //resetForm,
      //onSubmit,
    }
  },
}
</script>

<style scoped>
.field-highlight {
  background-color: rgba(var(--v-theme-warning), 0.15);
  border-radius: 4px;
  padding: 8px;
  margin: -8px;
  animation: highlight-pulse 2s ease-in-out;
}

@keyframes highlight-pulse {
  0%, 100% {
    background-color: rgba(var(--v-theme-warning), 0.15);
  }
  50% {
    background-color: rgba(var(--v-theme-warning), 0.25);
  }
}
</style>