/**
 * Weather Alert System - Map Functions
 * Current Date: 2025-01-26 01:57:44 UTC
 * Modified By: KR8MER
 */

let map;
let boundaryLayers = {
    townships: new L.LayerGroup(),
    villages: new L.LayerGroup(),
    electric: new L.LayerGroup(),
    fire: new L.LayerGroup(),
    ems: new L.LayerGroup(),
    school: new L.LayerGroup(),
    telephone: new L.LayerGroup()
};
let alertLayer = new L.LayerGroup();
let currentBoundaryStyle = 'dashed';

document.addEventListener('DOMContentLoaded', function() {
    initializeMap();
    loadBoundaries();
    loadAlerts();
});

function initializeMap() {
    // Initialize the map centered on Putnam County
    map = L.map('map').setView([41.0369, -84.1277], 10);

    // Add OpenStreetMap tiles
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors'
    }).addTo(map);

    // Add all layer groups to map
    Object.values(boundaryLayers).forEach(layer => layer.addTo(map));
    alertLayer.addTo(map);
}

function loadBoundaries() {
    // Load township boundaries
    if (window.mapData.townshipData) {
        L.geoJSON(window.mapData.townshipData, {
            style: () => ({
                color: '#6c757d',
                weight: 1,
                opacity: 0.6,
                fillOpacity: 0.05,
                dashArray: '3, 3'
            }),
            onEachFeature: (feature, layer) => {
                if (feature.properties && feature.properties.name) {
                    layer.bindPopup(feature.properties.name);
                }
            }
        }).addTo(boundaryLayers.townships);
    }

    // Load other boundaries (implementation remains the same)
    // ... (previous boundary loading code remains unchanged)
}

/**
 * Weather Alert System - Map Functions (continued)
 */

function loadAlerts() {
    if (window.mapData.alertData && window.mapData.alertData.features) {
        // Clear existing alert layer
        alertLayer.clearLayers();
        
        const urlParams = new URLSearchParams(window.location.search);
        const isHistorical = urlParams.get('historical') === 'true';
        
        const alertGeoJSON = L.geoJSON(window.mapData.alertData, {
            style: function(feature) {
                return {
                    color: getAlertColor(feature.properties.severity),
                    weight: isHistorical ? 3 : 2,
                    opacity: isHistorical ? 1 : 0.8,
                    fillOpacity: isHistorical ? 0.4 : 0.3,
                    dashArray: isHistorical ? '5, 5' : null
                };
            },
            onEachFeature: function(feature, layer) {
                layer.bindPopup(createAlertPopup(feature.properties));
                
                if (isHistorical) {
                    // Auto-zoom to historical alert
                    const bounds = layer.getBounds();
                    map.fitBounds(bounds, { padding: [50, 50] });
                }
                
                layer.on('mouseover', function() {
                    this.setStyle({
                        fillOpacity: isHistorical ? 0.6 : 0.5,
                        weight: isHistorical ? 4 : 3
                    });
                });
                
                layer.on('mouseout', function() {
                    this.setStyle({
                        fillOpacity: isHistorical ? 0.4 : 0.3,
                        weight: isHistorical ? 3 : 2
                    });
                });
            }
        }).addTo(alertLayer);

        // Update districts table
        updateDistrictsTable();
    }
}

function getAlertColor(severity) {
    const colors = {
        'extreme': '#dc3545',
        'severe': '#ffc107',
        'moderate': '#17a2b8'
    };
    return colors[severity.toLowerCase()] || '#6c757d';
}

function createAlertPopup(properties) {
    const expiresDate = new Date(properties.expires);
    let popupContent = `
        <div class="alert-popup">
            <h5>${properties.title}</h5>
            <p><strong>Severity:</strong> ${properties.severity}</p>
            <p><strong>Urgency:</strong> ${properties.urgency}</p>
            <p>${properties.description}</p>
            <p><small>Expires: ${expiresDate.toLocaleString()}</small></p>
    `;

    if (properties.districts) {
        const districts = properties.districts;
        if (Object.keys(districts).length > 0) {
            popupContent += '<div class="mt-2"><strong>Affected Districts:</strong>';
            if (districts.fire && districts.fire.length) {
                popupContent += `<div><span class="text-danger">Fire:</span> ${districts.fire.join(', ')}</div>`;
            }
            if (districts.ems && districts.ems.length) {
                popupContent += `<div><span class="text-success">EMS:</span> ${districts.ems.join(', ')}</div>`;
            }
            if (districts.electric && districts.electric.length) {
                popupContent += `<div><span class="text-warning">Electric:</span> ${districts.electric.join(', ')}</div>`;
            }
            popupContent += '</div>';
        }
    }

    popupContent += '</div>';
    return popupContent;
}

function updateDistrictsTable() {
    const container = document.getElementById('districtsTable');
    
    if (!window.mapData.alertData || !window.mapData.alertData.features || window.mapData.alertData.features.length === 0) {
        container.innerHTML = '<div class="alert alert-info">No active alerts</div>';
        return;
    }

    let allDistricts = {
        fire: new Set(),
        ems: new Set(),
        electric: new Set()
    };

    let alertDistricts = window.mapData.alertData.features.map(feature => {
        const districts = feature.properties.districts || {};
        return {
            id: feature.properties.id,
            title: feature.properties.title,
            severity: feature.properties.severity,
            districts: {
                fire: Array.isArray(districts.fire) ? districts.fire : [],
                ems: Array.isArray(districts.ems) ? districts.ems : [],
                electric: Array.isArray(districts.electric) ? districts.electric : []
            }
        };
    });

    alertDistricts.forEach(alert => {
        if (alert.districts) {
            Object.keys(allDistricts).forEach(type => {
                if (Array.isArray(alert.districts[type])) {
                    alert.districts[type].forEach(district => {
                        if (district) {
                            allDistricts[type].add(district);
                        }
                    });
                }
            });
        }
    });

    let tableHTML = `
        <div class="table-responsive">
            <table class="table table-bordered table-sm">
                <thead class="table-light">
                    <tr>
                        <th style="width: 40%;">Alert</th>
                        <th style="width: 60%;">Affected Districts</th>
                    </tr>
                </thead>
                <tbody>
    `;

    alertDistricts.forEach(alert => {
        tableHTML += `
            <tr>
                <td>
                    <div class="mb-1">${alert.title}</div>
                    <span class="badge bg-${getSeverityClass(alert.severity)}">
                        ${alert.severity}
                    </span>
                </td>
                <td>
                    ${formatDistrictsList(alert.districts)}
                </td>
            </tr>
        `;
    });

    tableHTML += `
                </tbody>
            </table>
            <div class="mt-3">
                <h6>All Affected Districts</h6>
                <div class="district-summary">
                    ${formatAllDistricts(allDistricts)}
                </div>
            </div>
        </div>
    `;

    container.innerHTML = tableHTML;
}

function formatDistrictsList(districts) {
    let html = '';
    
    if (districts.fire && districts.fire.length > 0) {
        html += `<div class="mb-1">
            <strong class="text-danger">Fire:</strong> ${districts.fire.sort().join(', ')}
        </div>`;
    }
    
    if (districts.ems && districts.ems.length > 0) {
        html += `<div class="mb-1">
            <strong class="text-success">EMS:</strong> ${districts.ems.sort().join(', ')}
        </div>`;
    }
    
    if (districts.electric && districts.electric.length > 0) {
        html += `<div>
            <strong class="text-warning">Electric:</strong> ${districts.electric.sort().join(', ')}
        </div>`;
    }
    
    return html || '<em class="text-muted">No specific districts affected</em>';
}

function formatAllDistricts(districts) {
    let html = '<div class="all-districts-summary">';
    
    if (districts.fire.size > 0) {
        html += `
            <div class="mb-2">
                <strong class="text-danger">All Fire Districts:</strong>
                <div class="ms-2">${Array.from(districts.fire).sort().join(', ')}</div>
            </div>
        `;
    }
    
    if (districts.ems.size > 0) {
        html += `
            <div class="mb-2">
                <strong class="text-success">All EMS Districts:</strong>
                <div class="ms-2">${Array.from(districts.ems).sort().join(', ')}</div>
            </div>
        `;
    }
    
    if (districts.electric.size > 0) {
        html += `
            <div class="mb-2">
                <strong class="text-warning">All Electric Districts:</strong>
                <div class="ms-2">${Array.from(districts.electric).sort().join(', ')}</div>
            </div>
        `;
    }
    
    html += '</div>';
    return html;
}

function getSeverityClass(severity) {
    const classes = {
        'extreme': 'danger',
        'severe': 'warning',
        'moderate': 'info'
    };
    return classes[severity.toLowerCase()] || 'secondary';
}

function toggleBoundaries(type) {
    const layer = boundaryLayers[type];
    if (!layer) return;

    const element = document.querySelector(`[onclick="toggleBoundaries('${type}')"]`);
    
    if (map.hasLayer(layer)) {
        map.removeLayer(layer);
        element?.classList.remove('active');
    } else {
        map.addLayer(layer);
        element?.classList.add('active');
    }
}

function changeBoundaryStyle(style) {
    if (!['solid', 'dashed', 'dotted'].includes(style)) return;
    
    currentBoundaryStyle = style;
    
    Object.values(boundaryLayers).forEach(layer => {
        const isVisible = map.hasLayer(layer);
        layer.clearLayers();
        if (!isVisible) {
            map.removeLayer(layer);
        }
    });
    
    loadBoundaries();
}

function focusAlert(alertId) {
    if (!window.mapData.alertData || !window.mapData.alertData.features) return;

    const alertFeature = window.mapData.alertData.features.find(
        f => f.properties.id === alertId
    );
    
    if (alertFeature) {
        const bounds = L.geoJSON(alertFeature).getBounds();
        map.fitBounds(bounds, { padding: [50, 50] });

        const layer = findAlertLayer(alertId);
        if (layer) {
            layer.setStyle({
                fillOpacity: 0.6,
                weight: 4
            });
            setTimeout(() => {
                layer.setStyle({
                    fillOpacity: 0.3,
                    weight: 2
                });
            }, 2000);
        }
    }
}

function findAlertLayer(alertId) {
    let targetLayer = null;
    alertLayer.eachLayer(layer => {
        if (layer.feature && layer.feature.properties.id === alertId) {
            targetLayer = layer;
        }
    });
    return targetLayer;
}

// Auto refresh every 5 minutes for non-historical views
if (!new URLSearchParams(window.location.search).get('historical')) {
    setInterval(() => {
        location.reload();
    }, 300000);
}