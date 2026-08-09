<template>
  <div class="responsive-menu">
    <!-- Darstellung bestimmt die Navbar per Messung (prop `mode`), nicht ein
         fester Breakpoint: wie viel Platz die Leiste braucht, haengt von
         Mandant, Sprache und aktiven Features ab. -->
    <div v-if="mode === 'text'" class="d-flex align-center">
      <template v-for="(menu, index) in menus" :key="index">
        <!-- Direkter Link ohne Untermenue -->
        <v-btn
          v-if="menu.to"
          variant="text"
          color="primary"
          class="px-2 text-none-wrap"
          :to="menu.to"
        >
          {{ menu.title }}
        </v-btn>

        <v-menu
          v-else
          location="bottom start"
          offset="8"
        >
          <template #activator="{ props }">
            <v-btn
              variant="text"
              color="primary"
              v-bind="props"
              class="px-2 text-none-wrap"
            >
              {{ menu.title }}
              <v-icon size="small" class="ms-1">mdi-chevron-down</v-icon>
            </v-btn>
          </template>

          <v-card min-width="220">
            <v-list density="compact">
              <template v-for="(item, itemIndex) in menu.items" :key="itemIndex">
                <v-divider v-if="item === '-'" class="my-1" />
                <v-list-item
                  v-else
                  :to="item.to"
                  @click="item.to ? null : handleMenuClick(item)"
                  :disabled="!item.to && !item.action"
                >
                  <v-list-item-title>{{ item.title }}</v-list-item-title>
                </v-list-item>
              </template>
            </v-list>
          </v-card>
        </v-menu>
      </template>
    </div>

    <!-- Zu wenig Platz fuer Text: nur Symbole mit Tooltip -->
    <div v-else-if="mode === 'icons'" class="d-flex align-center">
      <template v-for="(menu, index) in menus" :key="index">
        <!-- Direkter Link ohne Untermenue -->
        <v-tooltip v-if="menu.to" :text="menu.title" location="bottom">
          <template #activator="{ props: tooltipProps }">
            <v-btn
              icon
              variant="text"
              color="primary"
              v-bind="tooltipProps"
              size="small"
              :to="menu.to"
            >
              <v-icon>{{ menu.icon || 'mdi-menu' }}</v-icon>
            </v-btn>
          </template>
        </v-tooltip>

        <v-menu
          v-else
          location="bottom start"
          offset="8"
        >
          <template #activator="{ props: menuProps }">
            <v-tooltip :text="menu.title" location="bottom">
              <template #activator="{ props: tooltipProps }">
                <v-btn
                  icon
                  variant="text"
                  color="primary"
                  v-bind="{ ...menuProps, ...tooltipProps }"
                  size="small"
                >
                  <v-icon>{{ menu.icon || 'mdi-menu' }}</v-icon>
                </v-btn>
              </template>
            </v-tooltip>
          </template>

          <v-card min-width="220">
            <v-list density="compact">
              <template v-for="(item, itemIndex) in menu.items" :key="itemIndex">
                <v-divider v-if="item === '-'" class="my-1" />
                <v-list-item
                  v-else
                  :to="item.to"
                  @click="item.to ? null : handleMenuClick(item)"
                  :disabled="!item.to && !item.action"
                >
                  <v-list-item-title>{{ item.title }}</v-list-item-title>
                </v-list-item>
              </template>
            </v-list>
          </v-card>
        </v-menu>
      </template>
    </div>

    <!-- Ganz eng: alles unter einem Hamburger -->
    <div v-else class="d-flex">
      <v-menu location="bottom end" :close-on-content-click="false">
        <template #activator="{ props }">
          <v-btn
            icon
            variant="text"
            color="primary"
            v-bind="props"
          >
            <v-icon>mdi-menu</v-icon>
          </v-btn>
        </template>

        <v-card min-width="280" max-width="320">
          <v-list>
            <template v-for="(menu, menuIndex) in menus" :key="menuIndex">
              <!-- Direkter Link ohne Untermenue -->
              <v-list-item v-if="menu.to" :to="menu.to">
                <template #prepend>
                  <v-icon v-if="menu.icon" size="small" class="me-2">{{ menu.icon }}</v-icon>
                </template>
                <v-list-item-title class="font-weight-medium">
                  {{ menu.title }}
                </v-list-item-title>
              </v-list-item>

              <v-list-group v-else>
                <template #activator="{ props }">
                  <v-list-item v-bind="props">
                    <template #prepend>
                      <v-icon v-if="menu.icon" size="small" class="me-2">{{ menu.icon }}</v-icon>
                    </template>
                    <v-list-item-title class="font-weight-medium">
                      {{ menu.title }}
                    </v-list-item-title>
                  </v-list-item>
                </template>

                <template v-for="(item, itemIndex) in menu.items" :key="itemIndex">
                  <v-divider v-if="item === '-'" class="my-1" />
                  <v-list-item
                    v-else
                    :to="item.to"
                    @click="item.to ? null : handleMenuClick(item)"
                    :disabled="!item.to && !item.action"
                    class="ps-8"
                  >
                    <v-list-item-title>{{ item.title }}</v-list-item-title>
                  </v-list-item>
                </template>
              </v-list-group>
            </template>
          </v-list>
        </v-card>
      </v-menu>
    </div>
  </div>
</template>

<script>
export default {
  name: 'ResponsiveMenu',
  props: {
    menus: {
      type: Array,
      required: true
    },
    // 'text' | 'icons' | 'burger' — von der Navbar gemessen
    mode: {
      type: String,
      default: 'text'
    }
  },
  emits: ['menu-click'],
  setup(props, { emit }) {
    const handleMenuClick = (item) => {
      if (!item.to) {
        emit('menu-click', item)
      }
    }

    return {
      handleMenuClick
    }
  }
}
</script>

<style scoped>
/* Enge Textbuttons: so passt die Beschriftung auf 1920px noch komplett hinein,
   statt auf Symbole auszuweichen. */
.text-none-wrap {
  min-width: 0 !important;
  white-space: nowrap;
}
.responsive-menu {
  display: flex;
  align-items: center;
}
</style>
