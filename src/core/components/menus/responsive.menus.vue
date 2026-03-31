<template>
  <div class="responsive-menu">
    <!-- Desktop (>=xl / 1920px): Buttons mit Text -->
    <div class="d-none d-xl-flex align-center">
      <v-menu
        v-for="(menu, index) in menus"
        :key="index"
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
    </div>

    <!-- Tablet (md bis xl): Nur Icons mit Tooltip -->
    <div class="d-none d-md-flex d-xl-none align-center">
      <v-menu
        v-for="(menu, index) in menus"
        :key="index"
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
            <v-list-group
              v-for="(menu, menuIndex) in menus"
              :key="menuIndex"
            >
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
