// Debug mode
const DEBUG = true;

// Global variables
let map, countyLayer, townshipLayer, electricLayer, fireLayer, emsLayer, alertLayers = {};
let boundaryVisible = true;
let townshipsVisible = false;
let electricVisible = false;
let fireVisible = false;
let emsVisible = false;

// Initialize map when DOM is loaded
document.addEventListener("DOMContentLoaded", function() {
    console.log("DOM loaded, initializing map");
    initMap();
});

// Initialize map
function initMap() {
    try {
        console.log("Creating map");
        map = L.map("map").setView([41.0359, -84.1271], 10);
        
        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
            attribution: " OpenStreetMap contributors"
        }).addTo(map);
        
        // Add boundaries
        addBoundaries();
        // Add alerts
        addAlerts();
        
    } catch (error) {
        console.error("Error in map initialization:", error);
    }
}

// Helper function to find districts that intersect with a point
function findDistrictsForPoint(lat, lon) {
    let point = L.latLng(lat, lon);
    let districts = {
        fire: null,
        ems: null,
        electric: null
    };

    // Check fire districts
    if (fireLayer) {
        fireLayer.eachLayer(function(layer) {
            if (leafletPip.pointInLayer(point, layer, true).length > 0) {
                districts.fire = layer.feature.properties.DEPT || 'Unknown';
            }
        });
    }

    // Check EMS districts
    if (emsLayer) {
        emsLayer.eachLayer(function(layer) {
            if (leafletPip.pointInLayer(point, layer, true).length > 0) {
                districts.ems = layer.feature.properties.DEPT || 'Unknown';
            }
        });
    }

    // Check electric providers
    if (electricLayer) {
        electricLayer.eachLayer(function(layer) {
            if (leafletPip.pointInLayer(point, layer, true).length > 0) {
                districts.electric = layer.feature.properties.COMPNAME || 'Unknown';
            }
        });
    }

    return districts;
}
// Helper function to find districts for a polygon
function findDistrictsForPolygon(coordinates) {
    let districts = {
        fire: new Set(),
        ems: new Set(),
        electric: new Set()
    };

    // Process each coordinate pair in the polygon
    coordinates.forEach(coord => {
        const pointDistricts = findDistrictsForPoint(coord[0], coord[1]);
        if (pointDistricts.fire) districts.fire.add(pointDistricts.fire);
        if (pointDistricts.ems) districts.ems.add(pointDistricts.ems);
        if (pointDistricts.electric) districts.electric.add(pointDistricts.electric);
    });

    // Convert Sets to Arrays
    return {
        fire: Array.from(districts.fire),
        ems: Array.from(districts.ems),
        electric: Array.from(districts.electric)
    };
}

function addBoundaries() {
    // Add county boundary
    console.log("Adding county boundary");
    let boundaryData = window.mapData.boundaryData;
    
    if (boundaryData !== null) {
        try {
            if (typeof boundaryData === "string") {
                boundaryData = JSON.parse(boundaryData);
            }
            
            countyLayer = L.geoJSON(boundaryData, {
                style: {
                    color: "#6c757d",
                    weight: 2,
                    fillColor: "#6c757d",
                    fillOpacity: 0.1,
                    dashArray: "5,5"
                }
            }).addTo(map);
            
            // Fit map to county boundary
            map.fitBounds(countyLayer.getBounds());
            console.log("County boundary added successfully");
        } catch (e) {
            console.error("Error adding county boundary:", e);
        }
    }
    
    // Add township boundaries
    console.log("Adding township boundaries");
    let townshipData = window.mapData.townshipData;
    
    if (townshipData !== null) {
        try {
            if (typeof townshipData === "string") {
                townshipData = JSON.parse(townshipData);
            }
            
            townshipLayer = L.geoJSON(townshipData, {
                style: {
                    color: "#6c757d",
                    weight: 1,
                    fillColor: "#6c757d",
                    fillOpacity: 0.05,
                    dashArray: "3,3"
                },
                onEachFeature: function(feature, layer) {
                    if (feature.properties) {
                        layer.bindPopup(createTownshipPopup(feature.properties));
                    }

                    layer.on({
                        mouseover: function(e) {
                            layer.setStyle({
                                fillOpacity: 0.2,
                                weight: 2
                            });
                        },
                        mouseout: function(e) {
                            layer.setStyle({
                                fillOpacity: 0.05,
                                weight: 1
                            });
                        }
                    });
                }
            });
            console.log("Township boundaries ready");
        } catch (e) {
            console.error("Error adding township boundaries:", e);
        }
    }
// Add electric provider boundaries
    console.log("Adding electric provider boundaries");
    let electricData = window.mapData.electricData;
    
    if (electricData !== null) {
        try {
            if (typeof electricData === "string") {
                electricData = JSON.parse(electricData);
            }
            
            electricLayer = L.geoJSON(electricData, {
                style: {
                    color: "#ff9900",
                    weight: 1,
                    fillColor: "#ff9900",
                    fillOpacity: 0.1,
                    dashArray: "3,3"
                },
                onEachFeature: function(feature, layer) {
                    if (feature.properties) {
                        layer.bindPopup(createProviderPopup(feature.properties));
                    }

                    layer.on({
                        mouseover: function(e) {
                            layer.setStyle({
                                fillOpacity: 0.2,
                                weight: 2
                            });
                        },
                        mouseout: function(e) {
                            layer.setStyle({
                                fillOpacity: 0.1,
                                weight: 1
                            });
                        }
                    });
                }
            });
            console.log("Electric provider boundaries ready");
        } catch (e) {
            console.error("Error adding electric provider boundaries:", e);
        }
    }

    // Add fire district boundaries
    console.log("Adding fire district boundaries");
    let fireData = window.mapData.fireData;
    
    if (fireData !== null) {
        try {
            if (typeof fireData === "string") {
                fireData = JSON.parse(fireData);
            }
            
            fireLayer = L.geoJSON(fireData, {
                style: {
                    color: "#dc3545",
                    weight: 1,
                    fillColor: "#dc3545",
                    fillOpacity: 0.1,
                    dashArray: "3,3"
                },
                onEachFeature: function(feature, layer) {
                    if (feature.properties) {
                        layer.bindPopup(createFireDistrictPopup(feature.properties));
                    }

                    layer.on({
                        mouseover: function(e) {
                            layer.setStyle({
                                fillOpacity: 0.2,
                                weight: 2
                            });
                        },
                        mouseout: function(e) {
                            layer.setStyle({
                                fillOpacity: 0.1,
                                weight: 1
                            });
                        }
                    });
                }
            });
            console.log("Fire district boundaries ready");
        } catch (e) {
            console.error("Error adding fire district boundaries:", e);
        }
    }

    // Add EMS district boundaries
    console.log("Adding EMS district boundaries");
    let emsData = window.mapData.emsData;
    
    if (emsData !== null) {
        try {
            if (typeof emsData === "string") {
                emsData = JSON.parse(emsData);
            }
            
            emsLayer = L.geoJSON(emsData, {
                style: {
                    color: "#28a745",
                    weight: 1,
                    fillColor: "#28a745",
                    fillOpacity: 0.1,
                    dashArray: "3,3"
                },
                onEachFeature: function(feature, layer) {
                    if (feature.properties) {
                        layer.bindPopup(createEmsDistrictPopup(feature.properties));
                    }

                    layer.on({
                        mouseover: function(e) {
                            layer.setStyle({
                                fillOpacity: 0.2,
                                weight: 2
                            });
                        },
                        mouseout: function(e) {
                            layer.setStyle({
                                fillOpacity: 0.1,
                                weight: 1
                            });
                        }
                    });
                }
            });
            console.log("EMS district boundaries ready");
        } catch (e) {
            console.error("Error adding EMS district boundaries:", e);
        }
    }
}
function addAlerts() {
    console.log("Adding alerts");
    let alertData = window.mapData.alertData;
    
    if (alertData) {
        try {
            if (typeof alertData === "string") {
                alertData = JSON.parse(alertData);
            }
            
            if (alertData.features && alertData.features.length > 0) {
                alertData.features.forEach(feature => {
                    if (feature.geometry) {
                        // Find affected districts before creating popup
                        let affectedDistricts;
                        if (feature.geometry.type === 'Polygon') {
                            affectedDistricts = findDistrictsForPolygon(feature.geometry.coordinates[0]);
                            // Add districts to properties for popup creation
                            feature.properties.districts = affectedDistricts;
                        }
                        
                        // Alert has specific geometry
                        const layer = L.geoJSON(feature, {
                            style: getAlertStyle(feature.properties)
                        }).addTo(map);
                        
                        layer.bindPopup(createAlertPopup(feature.properties));
                        
                        if (feature.properties.id) {
                            alertLayers[feature.properties.id] = layer;
                        }
                    } else if (feature.properties.type === 'county-wide') {
                        // County-wide alert - use county boundary
                        if (window.mapData.boundaryData) {
                            // For county-wide alerts, include all districts
                            feature.properties.districts = {
                                fire: ['All Fire Districts'],
                                ems: ['All EMS Districts'],
                                electric: ['All Electric Providers']
                            };
                            
                            const layer = L.geoJSON(window.mapData.boundaryData, {
                                style: {
                                    ...getAlertStyle(feature.properties),
                                    fillOpacity: 0.2,
                                    dashArray: '5,10'
                                }
                            }).addTo(map);
                            
                            layer.bindPopup(createAlertPopup(feature.properties));
                            
                            if (feature.properties.id) {
                                alertLayers[feature.properties.id] = layer;
                            }
                        }
                    }
                });
                console.log("Alert layers added successfully");
            }
        } catch (e) {
            console.error("Error adding alert layers:", e);
        }
    }
}

function getAlertStyle(properties) {
    const styles = {
        "Extreme": {
            color: "#dc3545",
            weight: 2,
            fillColor: "#dc3545",
            fillOpacity: 0.3
        },
        "Severe": {
            color: "#ffc107",
            weight: 2,
            fillColor: "#ffc107",
            fillOpacity: 0.3
        },
        "Moderate": {
            color: "#17a2b8",
            weight: 2,
            fillColor: "#17a2b8",
            fillOpacity: 0.3
        }
    };
    return styles[properties.severity] || styles["Moderate"];
}

function createAlertPopup(properties) {
    const districts = properties.districts || {
        fire: [],
        ems: [],
        electric: []
    };

    return `
        <div class="alert-popup">
            <h6>${properties.title}</h6>
            <p>${properties.description}</p>
            <div class="alert-details">
                <small>
                    <strong>Severity:</strong> ${properties.severity}<br>
                    <strong>Urgency:</strong> ${properties.urgency}<br>
                    <strong>Expires:</strong> ${new Date(properties.expires).toLocaleString()}<br>
                    <strong>Type:</strong> ${properties.type === 'county-wide' ? 'County-Wide Alert' : 'Specific Area Alert'}<br>
                    <strong>Affected Districts:</strong><br>
                    Fire: ${districts.fire.join(', ') || 'None'}<br>
                    EMS: ${districts.ems.join(', ') || 'None'}<br>
                    Electric: ${districts.electric.join(', ') || 'None'}
                </small>
            </div>
        </div>
    `;
}
function createTownshipPopup(properties) {
    return `
        <div class="township-info">
            <h6>${properties.TOWNSHIP_N || "Unknown"} Township</h6>
            <p><strong>Population:</strong> ${properties.POPULATION ? properties.POPULATION.toLocaleString() : 'N/A'}</p>
            <p><strong>Area:</strong> ${properties.AREA_SQMI ? properties.AREA_SQMI.toFixed(2) : 'N/A'} sq mi</p>
        </div>
    `;
}

function createProviderPopup(properties) {
    return `
        <div class="provider-info">
            <h6>${properties.COMPNAME || "Unknown Provider"}</h6>
            <p><strong>Company Type:</strong> ${properties.COMPTYPE || 'N/A'}</p>
            <p><strong>Company Code:</strong> ${properties.COMPCODE || 'N/A'}</p>
            <p><strong>Service Area:</strong> ${(properties.area_sqmi || 0).toFixed(2)} sq mi</p>
        </div>
    `;
}

function createFireDistrictPopup(properties) {
    const areaSqMiles = ((properties.Shape_Area / 43560) / 640).toFixed(2);
    
    return `
        <div class="fire-info">
            <h6>${properties.DEPT || "Unknown Department"}</h6>
            <p><strong>Station:</strong> ${properties.STATION || 'N/A'}</p>
            <p><strong>Area:</strong> ${areaSqMiles} sq mi</p>
        </div>
    `;
}

function createEmsDistrictPopup(properties) {
    const areaSqMiles = ((properties.Shape_Area / 43560) / 640).toFixed(2);
    
    return `
        <div class="ems-info">
            <h6>${properties.DEPT || "Unknown Department"}</h6>
            <p><strong>Station:</strong> ${properties.STATION || 'N/A'}</p>
            <p><strong>Area:</strong> ${areaSqMiles} sq mi</p>
        </div>
    `;
}

function toggleBoundaries(type) {
    switch(type) {
        case "county":
            if (countyLayer) {
                boundaryVisible = !boundaryVisible;
                if (boundaryVisible) {
                    countyLayer.addTo(map);
                    console.log("County boundary shown");
                } else {
                    map.removeLayer(countyLayer);
                    console.log("County boundary hidden");
                }
            }
            break;
            
        case "townships":
            if (townshipLayer) {
                townshipsVisible = !townshipsVisible;
                if (townshipsVisible) {
                    townshipLayer.addTo(map);
                    console.log("Townships shown");
                } else {
                    map.removeLayer(townshipLayer);
                    console.log("Townships hidden");
                }
            }
            break;
            
        case "electric":
            if (electricLayer) {
                electricVisible = !electricVisible;
                if (electricVisible) {
                    electricLayer.addTo(map);
                    console.log("Electric providers shown");
                } else {
                    map.removeLayer(electricLayer);
                    console.log("Electric providers hidden");
                }
            }
            break;
            
        case "fire":
            if (fireLayer) {
                fireVisible = !fireVisible;
                if (fireVisible) {
                    fireLayer.addTo(map);
                    console.log("Fire districts shown");
                } else {
                    map.removeLayer(fireLayer);
                    console.log("Fire districts hidden");
                }
            }
            break;
            
        case "ems":
            if (emsLayer) {
                emsVisible = !emsVisible;
                if (emsVisible) {
                    emsLayer.addTo(map);
                    console.log("EMS districts shown");
                } else {
                    map.removeLayer(emsLayer);
                    console.log("EMS districts hidden");
                }
            }
            break;
    }
}

function changeBoundaryStyle(style) {
    const styles = {
        solid: null,
        dashed: "5,5",
        dotted: "1,5"
    };
    
    const layers = {
        county: countyLayer,
        township: townshipLayer,
        electric: electricLayer,
        fire: fireLayer,
        ems: emsLayer
    };
    
    Object.values(layers).forEach(layer => {
        if (layer) {
            layer.setStyle({
                dashArray: styles[style]
            });
        }
    });
    
    console.log("Boundary style changed to:", style);
}

function focusAlert(alertId) {
    const layer = alertLayers[alertId];
    if (layer) {
        map.fitBounds(layer.getBounds());
        layer.openPopup();
        console.log("Focused on alert:", alertId);
    }
}