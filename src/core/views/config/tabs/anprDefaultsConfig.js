// src/core/views/config/tabs/anprDefaultsConfig.js

const anprDefaultsConfig = [
    { name: "anpr_general", type: "headline", label: "anpr.general" },

    { name: "anpr_enabled", type: "checkbox", label: "anpr.enabled", tooltip: "anpr.enabled_help" },
    { name: "anpr_service_host", type: "input", size: 30, fieldstyle: "max-width: 30ch", label: "anpr.serviceHost", tooltip: "anpr.serviceHost_help" },
    { name: "anpr_service_port", type: "input", inputType: "number", size: 10, fieldstyle: "max-width: 15ch", label: "anpr.servicePort", tooltip: "anpr.servicePort_help" },

    { name: "anpr_detection", type: "headline", label: "anpr.detection" },

    { name: "anpr_show_unknown_vehicles", type: "checkbox", label: "anpr.showUnknownVehicles", tooltip: "anpr.showUnknownVehicles_help" },
    { name: "anpr_open_order_skip", type: "checkbox", label: "anpr.openOrderSkip", tooltip: "anpr.openOrderSkip_help" },
    { name: "anpr_detection_ttl_hours", type: "input", inputType: "number", size: 5, fieldstyle: "max-width: 15ch", label: "anpr.detectionTtlHours", tooltip: "anpr.detectionTtlHours_help" },
    { name: "anpr_infobar_max", type: "input", inputType: "number", size: 5, fieldstyle: "max-width: 15ch", label: "anpr.infobarMax", tooltip: "anpr.infobarMax_help" },
    { name: "anpr_blacklist", type: "textarea", rows: 3, fieldstyle: "max-width: 60ch", label: "anpr.blacklist", tooltip: "anpr.blacklist_help" },

    { name: "anpr_debug", type: "headline", label: "anpr.debug" },
    { name: "anpr_debug_snapshots", type: "checkbox", label: "anpr.debugSnapshots_label", tooltip: "anpr.debugSnapshots_help" },
];

export default anprDefaultsConfig;
