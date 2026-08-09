<template>
  <div class="responsive-menu">
    <!-- Ab md: Buttons mit Text — aber nur, solange sie wirklich hineinpassen.
         Ob das der Fall ist, misst die Navbar (prop `compact`); eine feste
         Breakpoint-Schwelle passte nicht, weil die Leiste je nach Mandant,
         Sprache und aktiven Features unterschiedlich viel Platz braucht. -->
    <div v-if="!compact" class="d-none d-md-flex align-center">
      <template v-for="(menu, index) in menus" :key="index">
        <!-- Direkter Link ohne Untermenue -->
        <v-btn
          v-if="menu.to"
          variant="text"
          color="primary"
          class="mx-1"
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
              class="mx-1"
            >
              {{ menu.title }}
              <v-icon end>mdi-chevron-down</v-icon>
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
    <div v-else class="d-none d-md-flex align-center">
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

    <!-- Mobile (<md): Hamburger-Menü -->
    <div class="d-flex d-md-none">
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
    // true = Symbolleiste statt Textbuttons (von der Navbar gemessen)
    compact: {
      type: Boolean,
      default: false
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
.responsive-menu {
  display: flex;
  align-items: center;
}
</style>
