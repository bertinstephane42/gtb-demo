const API_BASE = 'api/';
const POLL_INTERVAL = 1000;

let devices = [];
let pollingEnabled = true;
let pollTimer = null;
let currentDevice = null;
let currentView = 'dashboard';
let graphData = [];
let currentUserRole = null; // Rôle de l'utilisateur connecté

async function fetchDevices() {
    try {
        const response = await fetch(API_BASE + 'getDevices.php');
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        
        devices = await response.json();
        updateUI();
        updateConnectionStatus(true);
    } catch (error) {
        console.warn('Network error:', error.message);
        updateConnectionStatus(false);
        showToast('Perte réseau simulée', 'error');
    }
}

function updateConnectionStatus(online) {
    const statusEl = document.getElementById('connectionStatus');
    if (online) {
        statusEl.className = 'status-indicator online';
        statusEl.textContent = '● CONNECTÉ';
    } else {
        statusEl.className = 'status-indicator offline';
        statusEl.textContent = '○ DÉCONNECTÉ';
    }
    document.getElementById('lastUpdate').textContent = new Date().toLocaleTimeString();
}

function updateUI() {
    const deviceCount = devices.length;
    document.getElementById('deviceCount').textContent = deviceCount;
    
    let alarmsHH = 0, alarmsH = 0, alarmsL = 0, deviceGood = 0;
    let totalPower = 0, totalTemp = 0, totalCO2 = 0;
    let tempCount = 0, co2Count = 0;
    
    devices.forEach(d => {
        if (d.alarm === 'HH') alarmsHH++;
        else if (d.alarm === 'H') alarmsH++;
        else if (d.alarm === 'L') alarmsL++;
        
        if (d.alarm === 'NORMAL' || !d.alarm) deviceGood++;
        
        if (d.id === 'PWR-TOTAL') {
            totalPower = d.tag.value;
        }
        
        if (d.category === 'CVC' && d.tag.unit === '°C') {
            totalTemp += d.tag.value;
            tempCount++;
        }
        
        if (d.id.startsWith('CO2')) {
            totalCO2 += d.tag.value;
            co2Count++;
        }
    });
    
    document.getElementById('alarmHH').textContent = alarmsHH;
    document.getElementById('alarmH').textContent = alarmsH + alarmsL;
    document.getElementById('deviceGood').textContent = `${deviceGood} OK`;
    document.getElementById('totalPower').textContent = totalPower.toFixed(1);
    document.getElementById('avgTemp').textContent = tempCount > 0 ? (totalTemp / tempCount).toFixed(1) : '--';
    document.getElementById('avgCO2').textContent = co2Count > 0 ? Math.round(totalCO2 / co2Count) : '--';
    
    renderDevicesByCategory();
    updateFloorValues();
    updateGraphData();
}

function renderDevicesByCategory() {
    const categories = ['CVC', 'ENERGIE', 'ECLAIRAGE'];
    
    categories.forEach(cat => {
        const gridId = `devices-${cat}`;
        const grid = document.getElementById(gridId);
        if (!grid) return;
        
        grid.innerHTML = '';
        
        const catDevices = devices.filter(d => d.category === cat);
        catDevices.forEach(device => {
            const card = createDeviceCard(device);
            grid.appendChild(card);
        });
        
        if (catDevices.length === 0) {
            grid.innerHTML = '<p class="empty-message">Aucun équipement</p>';
        }
    });
}

function createDeviceCard(device) {
    const card = document.createElement('div');
    const alarmClass = device.alarm || 'NORMAL';
    const quality = device.tag.quality || 'GOOD';
    const qualityClass = quality === 'BAD' ? 'quality-bad' : quality === 'UNCERTAIN' ? 'quality-uncertain' : '';
    card.className = `device-card ${qualityClass} alarm-${alarmClass}`;
    card.onclick = () => openEditModal(device);
    
    const stateClass = device.state || 'AUTO';
    const value = typeof device.tag.value === 'number' ? 
                  device.tag.value.toFixed(1) : device.tag.value;
    
    const trendIcon = device.trend === 'RISING' ? '↗️' : device.trend === 'FALLING' ? '↘️' : '→';
    const anomalyBadge = device.anomaly ? '<span class="anomaly-badge">⚠️</span>' : '';
    
    card.innerHTML = `
        <div class="device-header">
            <span class="device-name">${device.name}</span>
            <span class="device-protocol">${device.protocol}</span>
        </div>
        <div class="device-value">${value}</div>
        <div class="device-unit">${device.tag.unit}</div>
        ${trendIcon !== '→' ? `<span class="trend-indicator">${trendIcon}</span>` : ''}
        ${anomalyBadge}
        ${quality !== 'GOOD' ? `<span class="quality-indicator quality-${quality.toLowerCase()}">${quality}</span>` : ''}
        <div class="device-footer">
            <span class="device-state ${stateClass}">${stateClass}</span>
            <span class="device-alarm ${alarmClass}">${alarmClass === 'NORMAL' ? 'OK' : alarmClass}</span>
        </div>
    `;
    
    return card;
}

async function fetchDeviceTrends() {
    try {
        const promises = devices.map(async (d) => {
            try {
                const response = await fetch(`${API_BASE}historian.php?device=${d.id}&limit=50&analyze=1`);
                const data = await response.json();
                return { id: d.id, ...data.analysis };
            } catch {
                return { id: d.id };
            }
        });
        
        const trends = await Promise.all(promises);
        
        devices.forEach(d => {
            const trendData = trends.find(t => t.id === d.id);
            if (trendData) {
                d.trend = trendData.trend;
                d.anomaly = trendData.anomaly;
            }
        });
        
        renderDevicesByCategory();
    } catch (e) {
        console.warn('Trend fetch failed:', e);
    }
}

function updateFloorValues() {
    const floorDevices = ['VAV-201', 'VAV-202', 'VAV-203', 'CO2-201', 'LUM-201', 'PWR-ETAGE1', 
                      'PWR-ETAGE2', 'LUM-203', 'CTA-01', 'GRP-FROID', 'CHAUDIERE', 'PWR-TOTAL', 
                      'LEAK-01'];
    
    floorDevices.forEach(id => {
        const device = devices.find(d => d.id === id);
        if (device) {
            const el = document.getElementById(`val-${id}`);
            if (el) {
                el.textContent = typeof device.tag.value === 'number' ? 
                    device.tag.value.toFixed(1) : device.tag.value;
            }
        }
    });
}

function openEditModal(device) {
    currentDevice = device;
    
    document.getElementById('modalTitle').textContent = `Modifier ${device.name}`;
    document.getElementById('editValue').value = device.tag.value;
    document.getElementById('editState').value = device.state;
    document.getElementById('valueUnit').textContent = device.tag.unit;
    
    document.getElementById('infoProtocol').textContent = device.protocol.toUpperCase();
    document.getElementById('infoAddr').textContent = device.addr || device.instance || '-';
    document.getElementById('infoDesc').textContent = device.description || device.name;
    
    const alarmEl = document.getElementById('infoAlarm');
    alarmEl.textContent = device.alarm || 'NORMAL';
    alarmEl.className = `info-value alarm-badge device-alarm ${device.alarm || 'NORMAL'}`;
    
    document.getElementById('editModal').classList.add('active');
}

function closeEditModal() {
    document.getElementById('editModal').classList.remove('active');
    currentDevice = null;
}

async function saveDevice() {
    if (!currentDevice) return;
    
    const value = parseFloat(document.getElementById('editValue').value);
    const state = document.getElementById('editState').value;
    
    try {
        const response = await fetch(API_BASE + 'writeDevice.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id: currentDevice.id,
                value: value,
                state: state
            })
        });
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        
        const result = await response.json();
        showToast(`${currentDevice.name} mis à jour`);
        closeEditModal();
        fetchDevices();
    } catch (error) {
        showToast('Erreur: ' + error.message, 'error');
    }
}

function showToast(message, type = 'success') {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.className = `toast ${type} show`;
    
    setTimeout(() => {
        toast.classList.remove('show');
    }, 3000);
}

function togglePolling() {
    pollingEnabled = !pollingEnabled;
    const btn = document.getElementById('togglePolling');
    const statusEl = document.getElementById('pollStatus');
    
    if (pollingEnabled) {
        btn.textContent = '⏸️ Pause';
        btn.classList.remove('btn-warning');
        statusEl.textContent = 'Polling 1s';
        statusEl.className = 'status-ok';
        pollTimer = setInterval(fetchDevices, POLL_INTERVAL);
    } else {
        btn.textContent = '▶️ Reprendre';
        btn.classList.add('btn-warning');
        statusEl.textContent = 'En pause';
        statusEl.className = 'status-warning';
        clearInterval(pollTimer);
    }
}

function setupNavigation() {
    const tabBtns = document.querySelectorAll('.tab-btn');
    
    tabBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const view = btn.dataset.view;
            console.log('Tab clicked:', view);
            
            tabBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            
            document.querySelectorAll('.view-section').forEach(section => {
                section.classList.remove('active');
            });
            
            const targetSection = document.getElementById('view-' + view);
            console.log('Section target:', targetSection?.id, 'wasActive:', targetSection?.classList?.contains('active'));
            
            if (targetSection) {
                targetSection.classList.add('active');
                
                // DEBUG: Force display for users
                if (view === 'users') {
                    targetSection.style.display = 'block';
                    console.log('✅ Forced users display:block');
                }
            }
            
            currentView = view;
            
            if (view === 'events') {
                loadEvents();
            } else if (view === 'graphs') {
                drawGraph();
            } else if (view === 'alarms') {
                fetchAlarms();
            } else if (view === 'scenarios') {
                fetchScenarios();
            } else if (view === 'control') {
                loadControlPanel();
            } else if (view === 'protocols') {
                fetchProtocolLogs();
            } else if (view === 'users') {
                fetchUsers();
            }
        });
    });
}

async function loadEvents() {
    const tbody = document.getElementById('eventsBody');
    
    try {
        const response = await fetch('data/events.json');
        if (!response.ok) {
            tbody.innerHTML = '<tr><td colspan="4">Aucun événement</td></tr>';
            return;
        }
        
        const events = await response.json();
        
        if (!events || events.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4">Aucun événement</td></tr>';
            return;
        }
        
        const recentEvents = events.slice(-50).reverse();
        
        tbody.innerHTML = recentEvents.map(e => {
            const date = new Date(e.ts * 1000);
            const time = date.toLocaleTimeString();
            const severityClass = `severity-${e.severity}`;
            
            return `
                <tr>
                    <td>${time}</td>
                    <td>${e.type}</td>
                    <td>${e.message}</td>
                    <td class="${severityClass}">${e.severity}</td>
                </tr>
            `;
        }).join('');
    } catch (error) {
        tbody.innerHTML = '<tr><td colspan="4">Erreur de chargement</td></tr>';
    }
}

async function loadGraphData() {
    const period = parseInt(document.getElementById('graphPeriod')?.value || '86400');
    const deviceSelect = document.getElementById('graphDevices');
    const selectedDevices = Array.from(deviceSelect?.selectedOptions || []).map(o => o.value);
    
    if (selectedDevices.length === 0) {
        selectedDevices.push(deviceSelect?.value || 'PWR-TOTAL');
    }
    
    const now = Math.floor(Date.now() / 1000);
    const from = now - period;
    
    try {
        const promises = selectedDevices.map(async (deviceId) => {
            const response = await fetch(`${API_BASE}historian.php?device=${deviceId}&from=${from}&to=${now}`);
            return response.json();
        });
        
        const results = await Promise.all(promises);
        
        graphData = results.map((data, index) => ({
            deviceId: selectedDevices[index],
            data: data.history || []
        }));
        
        drawMultiGraph();
        showToast(`${selectedDevices.length}设备 chargé(s)`, 'success');
    } catch (error) {
        showToast('Erreur chargement données', 'error');
    }
}

function drawMultiGraph() {
    const canvas = document.getElementById('dataCanvas');
    if (!canvas) return;
    
    const ctx = canvas.getContext('2d');
    const width = canvas.clientWidth || 800;
    const height = canvas.clientHeight || 350;
    
    canvas.width = width;
    canvas.height = height;
    
    ctx.fillStyle = '#12141a';
    ctx.fillRect(0, 0, width, height);
    
    if (!graphData || graphData.length === 0 || graphData.every(g => !g.data || g.data.length === 0)) {
        ctx.fillStyle = '#8b95a5';
        ctx.font = '14px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('Données insuffisantes', width / 2, height / 2);
        return;
    }
    
    const colors = ['#3b82f6', '#22c55e', '#f97316', '#06b6d4', '#a855f7', '#ef4444'];
    const padding = 50;
    const graphWidth = width - padding * 2;
    const graphHeight = height - padding * 2;
    
    let globalMin = Infinity, globalMax = -Infinity;
    graphData.forEach(series => {
        if (series.data && series.data.length > 0) {
            const vals = series.data.map(d => d.value);
            globalMin = Math.min(globalMin, ...vals);
            globalMax = Math.max(globalMax, ...vals);
        }
    });
    
    const range = globalMax - globalMin || 1;
    
    ctx.strokeStyle = '#374151';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(padding, padding);
    ctx.lineTo(padding, height - padding);
    ctx.lineTo(width - padding, height - padding);
    ctx.stroke();
    
    ctx.fillStyle = '#8b95a5';
    ctx.font = '10px sans-serif';
    ctx.textAlign = 'right';
    ctx.fillText(globalMax.toFixed(1), padding - 5, padding + 8);
    ctx.fillText(globalMin.toFixed(1), padding - 5, height - padding + 8);
    ctx.fillText(((globalMax + globalMin) / 2).toFixed(1), padding - 5, height / 2 + 4);
    
    graphData.forEach((series, idx) => {
        if (!series.data || series.data.length < 2) return;
        
        const color = colors[idx % colors.length];
        
        ctx.beginPath();
        ctx.strokeStyle = color;
        ctx.lineWidth = 2;
        
        series.data.forEach((point, i) => {
            const x = padding + (i / (series.data.length - 1)) * graphWidth;
            const y = height - padding - ((point.value - globalMin) / range) * graphHeight;
            
            if (i === 0) ctx.moveTo(x, y);
            else ctx.lineTo(x, y);
        });
        
        ctx.stroke();
        
        ctx.fillStyle = color;
        ctx.font = '11px sans-serif';
        ctx.textAlign = 'left';
        ctx.fillText(series.deviceId, width - 60, padding + 15 + idx * 15);
    });
}

function exportGraphData() {
    const period = document.getElementById('graphPeriod')?.value || '86400';
    const deviceSelect = document.getElementById('graphDevices');
    const selectedDevices = Array.from(deviceSelect?.selectedOptions || []).map(o => o.value);
    
    if (selectedDevices.length === 0) {
        selectedDevices.push('PWR-TOTAL');
    }
    
    const now = Math.floor(Date.now() / 1000);
    const from = now - parseInt(period);
    
    const csvContent = ['timestamp,device,value,unit'];
    
    selectedDevices.forEach(async (deviceId) => {
        const response = await fetch(`${API_BASE}historian.php?device=${deviceId}&from=${from}&to=${now}`);
        const data = await response.json();
        
        (data.history || []).forEach(point => {
            csvContent.push(`${point.ts},${deviceId},${point.value},${point.unit || ''}`);
        });
    });
    
    const blob = new Blob([csvContent.join('\n')], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `export_${period}s_${Date.now()}.csv`;
    a.click();
    URL.revokeObjectURL(url);
    
    showToast('Exporté en CSV', 'success');
}

function drawGraph() {
    const canvas = document.getElementById('dataCanvas');
    if (!canvas) return;
    
    const ctx = canvas.getContext('2d');
    const width = canvas.clientWidth || 800;
    const height = canvas.clientHeight || 300;
    
    canvas.width = width;
    canvas.height = height;
    
    ctx.fillStyle = '#12141a';
    ctx.fillRect(0, 0, width, height);
    
    if (graphData.length < 2) {
        ctx.fillStyle = '#8b95a5';
        ctx.font = '14px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('Données insuffisantes', width / 2, height / 2);
        return;
    }
    
    const values = graphData.map(d => d.value);
    const minVal = Math.min(...values);
    const maxVal = Math.max(...values);
    const range = maxVal - minVal || 1;
    
    const padding = 40;
    const graphWidth = width - padding * 2;
    const graphHeight = height - padding * 2;
    
    ctx.strokeStyle = '#374151';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(padding, padding);
    ctx.lineTo(padding, height - padding);
    ctx.lineTo(width - padding, height - padding);
    ctx.stroke();
    
    ctx.fillStyle = '#8b95a5';
    ctx.font = '11px sans-serif';
    ctx.textAlign = 'right';
    ctx.fillText(maxVal.toFixed(1), padding - 5, padding + 10);
    ctx.fillText(minVal.toFixed(1), padding - 5, height - padding + 10);
    
    const gradient = ctx.createLinearGradient(0, 0, 0, height);
    gradient.addColorStop(0, 'rgba(59, 130, 246, 0.3)');
    gradient.addColorStop(1, 'rgba(59, 130, 246, 0)');
    
    ctx.beginPath();
    ctx.moveTo(padding, height - padding);
    
    graphData.forEach((d, i) => {
        const x = padding + (i / (graphData.length - 1)) * graphWidth;
        const y = height - padding - ((d.value - minVal) / range) * graphHeight;
        
        if (i === 0) {
            ctx.lineTo(x, y);
        } else {
            ctx.lineTo(x, y);
        }
    });
    
    ctx.lineTo(width - padding, height - padding);
    ctx.closePath();
    ctx.fillStyle = gradient;
    ctx.fill();
    
    ctx.beginPath();
    ctx.strokeStyle = '#3b82f6';
    ctx.lineWidth = 2;
    
    graphData.forEach((d, i) => {
        const x = padding + (i / (graphData.length - 1)) * graphWidth;
        const y = height - padding - ((d.value - minVal) / range) * graphHeight;
        
        if (i === 0) {
            ctx.moveTo(x, y);
        } else {
            ctx.lineTo(x, y);
        }
    });
    
    ctx.stroke();
    
    ctx.fillStyle = '#e4e7eb';
    ctx.textAlign = 'center';
    const deviceSelect = document.getElementById('graphDevices');
    const deviceId = deviceSelect?.selectedOptions?.[0]?.value || 'PWR-TOTAL';
    ctx.fillText(deviceId, width / 2, 20);
}

function updateGraphData() {
    if (currentView !== 'graphs') return;
    
    if (graphData.length > 0) {
        const deviceSelect = document.getElementById('graphDevices');
        const deviceId = deviceSelect?.selectedOptions?.[0]?.value || 'PWR-TOTAL';
        const device = devices.find(d => d.id === deviceId);
        
        if (device) {
            graphData.push({
                value: device.tag.value,
                ts: Date.now() / 1000
            });
            
            if (graphData.length > 100) {
                graphData.shift();
            }
            
            drawGraph();
        }
    }
}

function ackAlarms() {
    document.querySelectorAll('.alarm-checkbox:not(:checked)').forEach(cb => {
        cb.checked = true;
    });
    document.getElementById('ackSelectedAlarms')?.click();
}

function updateAlarmBanner(data) {
    const banner = document.getElementById('alarmBanner');
    const countEl = document.getElementById('alarmBannerCount');
    const messageEl = document.getElementById('alarmBannerMessage');
    
    if (!banner || !countEl || !messageEl) return;
    
    const hhCount = data.counts?.HH || 0;
    const unackCount = data.unack_count || 0;
    const totalActive = (data.counts?.HH || 0) + (data.counts?.H || 0) + (data.counts?.L || 0) + (data.counts?.LL || 0);
    
    countEl.textContent = totalActive;
    
    if (hhCount > 0) {
        banner.className = 'alarm-banner alarm-critical';
        messageEl.textContent = `CRITIQUE: ${hhCount} alarme(s) HH`;
    } else if (unackCount > 0) {
        banner.className = 'alarm-banner alarm-warning';
        messageEl.textContent = `${unackCount} alarme(s) non acquittée(s)`;
    } else {
        banner.className = 'alarm-banner hidden';
    }
}

async function fetchAlarms() {
    try {
        const severityFilter = document.getElementById('filterSeverity')?.value || '';
        
        let url = API_BASE + 'alarms.php';
        if (severityFilter) {
            url += '?severity=' + severityFilter;
        }
        
        const response = await fetch(url);
        const data = await response.json();
        
        document.getElementById('countHH').textContent = data.counts?.HH || 0;
        document.getElementById('countH').textContent = data.counts?.H || 0;
        document.getElementById('countL').textContent = data.counts?.L || 0;
        document.getElementById('countLL').textContent = data.counts?.LL || 0;
        
        updateAlarmBanner(data);
        
        if (data.floor_counts) {
            const floorSummary = document.getElementById('floorCounts');
            if (floorSummary) {
                floorSummary.innerHTML = Object.entries(data.floor_counts)
                    .map(function(entry) { return '<span class="floor-count">' + entry[0] + ': ' + entry[1] + '</span>'; }).join('');
            }
        }
        
        const tbody = document.getElementById('alarmsBody');
        if (!data.alarms || data.alarms.length === 0) {
            tbody.innerHTML = '<tr><td colspan="11">Aucune alarme</td></tr>';
            return;
        }
        
        tbody.innerHTML = data.alarms.map(function(alarm) {
            var quality = alarm.quality || 'GOOD';
            var rowClass = 'alarm-row alarm-' + alarm.severity.toLowerCase();
            if (quality === 'BAD') rowClass += ' quality-bad';
            else if (quality === 'UNCERTAIN') rowClass += ' quality-uncertain';
            
            return '<tr class="' + rowClass + '">' +
                '<td><input type="checkbox" class="alarm-checkbox" data-id="' + alarm.id + '"></td>' +
                '<td>' + alarm.id + '</td>' +
                '<td>' + alarm.device_id + '</td>' +
                '<td>' + alarm.name + '</td>' +
                '<td><span class="severity-badge severity-' + alarm.severity.toLowerCase() + '">' + alarm.severity + '</span></td>' +
                '<td>' + (alarm.value ? alarm.value.toFixed(1) : '0') + ' ' + (alarm.unit || '') + '</td>' +
                '<td>' + alarm.threshold + ' ' + (alarm.unit || '') + '</td>' +
                '<td>' + new Date(alarm.ts * 1000).toLocaleString() + '</td>' +
                '<td><span class="quality-badge quality-' + quality.toLowerCase() + '">' + quality + '</span></td>' +
                '<td>' + (alarm.ack ? '✓' : '—') + '</td>' +
                '<td>' + (!alarm.ack ? '<button class="btn-small btn-ack" data-id="' + alarm.id + '">ACK</button>' : '') +
                (!alarm.shelved ? '<button class="btn-small btn-shelve" data-id="' + alarm.id + '">⏱</button>' : '') +
                '</td></tr>';
        }).join('');
        
        document.querySelectorAll('.btn-ack').forEach(btn => {
            btn.addEventListener('click', () => ackAlarm(btn.dataset.id));
        });
        document.querySelectorAll('.btn-shelve').forEach(btn => {
            btn.addEventListener('click', () => shelveAlarm(btn.dataset.id, 300));
        });
    } catch (error) {
        console.error('Error fetching alarms:', error);
    }
}

async function ackAlarm(alarmId) {
    try {
        const response = await fetch(`${API_BASE}alarms.php?id=${alarmId}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user: 'operator', action: 'ack' })
        });
        const data = await response.json();
        if (data.status === 'OK') {
            showToast(`Alarme ${alarmId} acquittée`, 'success');
            fetchAlarms();
        }
    } catch (error) {
        console.error('Error acking alarm:', error);
    }
}

async function shelveAlarm(alarmId, duration = 300) {
    try {
        const response = await fetch(`${API_BASE}alarms.php?id=${alarmId}&duration=${duration}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user: 'operator', action: 'shelve' })
        });
        const data = await response.json();
        if (data.status === 'OK') {
            showToast(`Alarme temporisée ${duration}s`, 'info');
            fetchAlarms();
        }
    } catch (error) {
        console.error('Error shelving alarm:', error);
    }
}

async function fetchScenarios() {
    try {
        const response = await fetch(API_BASE + 'scenarios.php');
        const data = await response.json();
        
        const container = document.getElementById('scenariosList');
        if (!data.scenarios || data.scenarios.length === 0) {
            container.innerHTML = '<p class="empty-message">Aucun scénario</p>';
            return;
        }
        
        container.innerHTML = data.scenarios.map(scenario => `
            <div class="scenario-card ${scenario.enabled ? 'enabled' : ''}">
                <div class="scenario-header">
                    <h4>${scenario.name}</h4>
                    <label class="toggle-switch">
                        <input type="checkbox" class="scenario-toggle" data-id="${scenario.id}" ${scenario.enabled ? 'checked' : ''}>
                        <span class="toggle-slider"></span>
                    </label>
                </div>
                <p class="scenario-desc">${scenario.description || ''}</p>
                <div class="scenario-info">
                    <span>Type: ${scenario.schedule?.type || 'N/A'}</span>
                    <span>Priorité: ${scenario.priority}</span>
                </div>
                <div class="scenario-actions">
                    <button class="btn-small btn-run" data-id="${scenario.id}">▶</button>
                    <button class="btn-small btn-delete" data-id="${scenario.id}">🗑</button>
                </div>
            </div>
        `).join('');
        
        document.querySelectorAll('.scenario-toggle').forEach(toggle => {
            toggle.addEventListener('change', () => toggleScenario(toggle.dataset.id, toggle.checked));
        });
        document.querySelectorAll('.btn-run').forEach(btn => {
            btn.addEventListener('click', () => runScenario(btn.dataset.id));
        });
    } catch (error) {
        console.error('Error fetching scenarios:', error);
    }
}

async function toggleScenario(id, enabled) {
    try {
        await fetch(API_BASE + 'scenarios.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, enabled })
        });
        showToast(`Scénario ${enabled ? 'activé' : 'désactivé'}`, 'info');
    } catch (error) {
        console.error('Error toggling scenario:', error);
    }
}

async function runScenario(id) {
    try {
        const response = await fetch(API_BASE + 'scenarios.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, trigger: true })
        });
        showToast(`Scénario exécuté`, 'success');
    } catch (error) {
        console.error('Error running scenario:', error);
    }
}

async function fetchProtocolLogs() {
    try {
        const filter = document.getElementById('protocolFilter')?.value || '';
        const url = filter ? `${API_BASE}protocolLog.php?protocol=${filter}` : API_BASE + 'protocolLog.php';
        const response = await fetch(url);
        const data = await response.json();
        
        document.getElementById('countBACNET').textContent = data.counts?.BACNET || 0;
        document.getElementById('countKNX').textContent = data.counts?.KNX || 0;
        document.getElementById('countMQTT').textContent = data.counts?.MQTT || 0;
        
        const container = document.getElementById('protocolsLog');
        container.querySelector('pre').textContent = data.logs?.join('\n') || 'Aucun log';
    } catch (error) {
        console.error('Error fetching protocol logs:', error);
    }
}

async function exportProtocols() {
    window.open(API_BASE + 'export.php?type=history&format=csv', '_blank');
}

async function loadControlPanel() {
    var container = document.getElementById('controlList');
    if (!container) return;
    
    var html = devices.map(function(d) {
        var autoSel = d.state === 'AUTO' ? 'selected' : '';
        var manuSel = d.state === 'MANU' ? 'selected' : '';
        var offSel = d.state === 'OFF' ? 'selected' : '';
        
        return '<div class="control-card">' +
            '<div class="control-name">' + d.name + '</div>' +
            '<div class="control-value">' + d.tag.value + ' ' + d.tag.unit + '</div>' +
            '<div class="control-state">' +
            '<select class="state-select" data-id="' + d.id + '">' +
            '<option value="AUTO" ' + autoSel + '>Auto</option>' +
            '<option value="MANU" ' + manuSel + '>Manu</option>' +
            '<option value="OFF" ' + offSel + '>Off</option>' +
            '</select></div>' +
            '<button class="btn-small btn-set" data-id="' + d.id + '">OK</button></div>';
    }).join('');
    
    container.innerHTML = html;
    
    var btns = container.querySelectorAll('.btn-set');
    for (var i = 0; i < btns.length; i++) {
        btns[i].addEventListener('click', (function(btn) {
            return function() {
                var select = container.querySelector('.state-select[data-id="' + btn.dataset.id + '"]');
                setDeviceState(btn.dataset.id, select.value);
            };
        })(btns[i]));
    }
}

async function setDeviceState(id, state, value = null) {
    try {
        var payload = { id, state };
        if (value !== null) {
            payload.value = value;
        }
        await fetch(API_BASE + 'writeDevice.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        showToast(`État ${id}: ${state}` + (value !== null ? ` (${value})` : ''), 'success');
        fetchDevices();
    } catch (error) {
        console.error('Error setting device state:', error);
    }
}

async function turnAllOn() {
    var promises = [];
    for (var i = 0; i < devices.length; i++) {
        var d = devices[i];
        if (d.id.startsWith('LUM') || d.id.startsWith('CTA')) {
            var maxVal = d.tag.max || 100;
            promises.push(setDeviceState(d.id, 'MANU', maxVal));
        }
    }
    await Promise.all(promises);
    showToast('Tout allumé', 'success');
}

async function turnAllOff() {
    var promises = [];
    for (var i = 0; i < devices.length; i++) {
        var d = devices[i];
        if (d.id.startsWith('LUM') || d.id.startsWith('CTA')) {
            promises.push(setDeviceState(d.id, 'MANU', 0));
        }
    }
    await Promise.all(promises);
    showToast('Tout éteint', 'warning');
}

async function setAllAuto() {
    var promises = [];
    for (var i = 0; i < devices.length; i++) {
        promises.push(setDeviceState(devices[i].id, 'AUTO'));
    }
    await Promise.all(promises);
    showToast('Tout en mode Auto', 'success');
}

function startPolling() {
    pollTimer = setInterval(fetchDevices, POLL_INTERVAL);
    setInterval(fetchDeviceTrends, 10000);
    
    setTimeout(() => {
        fetchDeviceTrends();
    }, 2000);
}

document.getElementById('refreshBtn').addEventListener('click', fetchDevices);
document.getElementById('togglePolling').addEventListener('click', togglePolling);
document.getElementById('ackAlarm').addEventListener('click', ackAlarms);
document.getElementById('closeModal').addEventListener('click', closeEditModal);
document.getElementById('cancelEdit').addEventListener('click', closeEditModal);
document.getElementById('saveEdit').addEventListener('click', saveDevice);
document.getElementById('loadGraph')?.addEventListener('click', loadGraphData);
document.getElementById('exportGraph')?.addEventListener('click', exportGraphData);

// Alarms view
document.getElementById('refreshAlarms')?.addEventListener('click', fetchAlarms);
document.getElementById('filterSeverity')?.addEventListener('change', fetchAlarms);
document.getElementById('ackSelectedAlarms')?.addEventListener('click', () => {
    document.querySelectorAll('.alarm-checkbox:checked').forEach(cb => ackAlarm(cb.dataset.id));
});
document.getElementById('shelveSelectedAlarms')?.addEventListener('click', () => {
    document.querySelectorAll('.alarm-checkbox:checked').forEach(cb => shelveAlarm(cb.dataset.id, 300));
});

// Scenarios view
document.getElementById('refreshScenarios')?.addEventListener('click', fetchScenarios);
document.getElementById('runScenarios')?.addEventListener('click', async () => {
    await fetch(API_BASE + 'scenarios.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'run' })
    });
    showToast('Scénarios exécutés', 'success');
    fetchDevices();
});

// Users view
document.getElementById('refreshUsers')?.addEventListener('click', fetchUsers);
document.getElementById('addUser')?.addEventListener('click', showAddUserModal);

async function fetchUsers() {
    // Check admin role first
    if (currentUserRole !== 'admin') {
        console.log('Access denied - not admin, role:', currentUserRole);
        return;
    }
    
    let tbody = document.getElementById('usersBody');
    if (!tbody) {
        return;
    }
    
    try {
        const res = await fetch('api/auth.php?list=1&demo=1');
        const data = await res.json();
        
        if (!data.users || data.count === 0) {
            tbody.innerHTML = '<tr><td colspan="7">Aucun utilisateur</td></tr>';
            return;
        }
        
        let rows = '';
        data.users.forEach(u => {
            const st = u.active ? 'Actif' : 'Inactif';
            const stCl = u.active ? 'status-active' : 'status-inactive';
            const last = u.last_login ? new Date(u.last_login*1000).toLocaleString() : 'Jamais';
            rows += `<tr>
                <td>${u.id}</td>
                <td>${u.name}</td>
                <td><span class="role-badge role-${u.role}">${u.role}</span></td>
                <td>${u.email}</td>
                <td><span class="${stCl}">${st}</span></td>
                <td>${last}</td>
                <td><button class="btn-small">Action</button></td>
            </tr>`;
        });
        
        tbody.innerHTML = rows;
        console.log('4. ✅ HTML SET - checking display...');
        console.log('   tbody.parent display:', getComputedStyle(tbody.parentElement).display);
        console.log('   tbody.parent parent display:', getComputedStyle(tbody.parentElement.parentElement).display);
        
    } catch(e) {
        console.error('❌ ERROR:', e);
    }
    
    console.log('=== fetchUsers END ===');
}

async function toggleUserActive(id, active) {
    try {
        const response = await fetch(`${API_BASE}auth.php?id=${id}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ active })
        });
        const data = await response.json();
        if (data.status === 'OK') {
            showToast(`Utilisateur ${active ? 'activé' : 'désactivé'}`, 'success');
            fetchUsers();
        }
    } catch (error) {
        console.error('Error toggling user:', error);
    }
}

async function deleteUser(id) {
    if (!confirm('Êtes-vous sûr de vouloir supprimer cet utilisateur?')) return;
    
    try {
        const response = await fetch(`${API_BASE}auth.php?id=${id}`, { method: 'DELETE' });
        const data = await response.json();
        if (data.status === 'OK') {
            showToast('Utilisateur supprimé', 'warning');
            fetchUsers();
        }
    } catch (error) {
        console.error('Error deleting user:', error);
    }
}

function showAddUserModal() {
    const name = prompt('Nom de l\'utilisateur:');
    if (!name) return;
    
    const username = prompt('Nom d\'utilisateur:');
    if (!username) return;
    
    const password = prompt('Mot de passe:');
    if (!password) return;
    
    const role = prompt('Rôle (admin/operator/viewer):', 'viewer');
    const email = prompt('Email:', '');
    
    createUser({ name, username, password, role, email });
}

async function createUser(userData) {
    try {
        const response = await fetch(`${API_BASE}auth.php?register=1`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(userData)
        });
        const data = await response.json();
        if (data.status === 'OK') {
            showToast('Utilisateur créé', 'success');
            fetchUsers();
        } else {
            showToast(data.error || 'Erreur', 'error');
        }
    } catch (error) {
        console.error('Error creating user:', error);
    }
}

// Control view
document.getElementById('turnAllOn')?.addEventListener('click', turnAllOn);
document.getElementById('turnAllOff')?.addEventListener('click', turnAllOff);
document.getElementById('setAllAuto')?.addEventListener('click', setAllAuto);

// Protocols view
document.getElementById('refreshProtocols')?.addEventListener('click', fetchProtocolLogs);
document.getElementById('protocolFilter')?.addEventListener('change', fetchProtocolLogs);
document.getElementById('clearProtocols')?.addEventListener('click', async () => {
    await fetch(API_BASE + 'protocolLog.php?clear=1', { method: 'POST' });
    showToast('Logs purgés', 'warning');
    fetchProtocolLogs();
});
document.getElementById('exportProtocols')?.addEventListener('click', exportProtocols);

document.getElementById('editModal')?.addEventListener('click', (e) => {
    if (e.target.id === 'editModal') closeEditModal();
});

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeEditModal();
});

setupNavigation();
fetchDevices();
fetchUsers();
startPolling();
checkAuth();

// Alarm banner dismiss
document.getElementById('dismissBanner')?.addEventListener('click', () => {
    const banner = document.getElementById('alarmBanner');
    if (banner) {
        banner.className = 'alarm-banner hidden';
    }
});

// Login functions
async function checkAuth() {
    try {
        const response = await fetch(API_BASE + 'auth.php?check=1');
        const data = await response.json();
        if (data.authenticated) {
            showUserSection(data);
            
            // Store and manage user role
            currentUserRole = data.role || data.user?.role;
            
            // Show/hide Users tab based on role
            const usersTab = document.querySelector('[data-view="users"]');
            if (usersTab) {
                if (currentUserRole === 'admin') {
                    usersTab.style.display = '';
                } else {
                    usersTab.style.display = 'none';
                }
            }
        } else {
            showLoginModal();
            currentUserRole = null;
        }
    } catch (e) {
        console.error('Auth check failed:', e);
        showLoginModal();
    }
}

function showUserSection(user) {
    const section = document.getElementById('userSection');
    const display = document.getElementById('userDisplay');
    if (section && display) {
        display.textContent = `${user.name} (${user.role})`;
        section.style.display = 'flex';
    }
}

function showLoginModal() {
    const modal = document.getElementById('loginModal');
    if (modal) {
        modal.classList.add('active');
    }
}

function hideLoginModal() {
    const modal = document.getElementById('loginModal');
    if (modal) {
        modal.classList.remove('active');
    }
}

async function doLogin() {
    const username = document.getElementById('loginUsername').value;
    const password = document.getElementById('loginPassword').value;
    const errorEl = document.getElementById('loginError');
    
    try {
        const response = await fetch(API_BASE + 'auth.php?login=1', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username, password })
        });
        const data = await response.json();
        
        if (data.status === 'OK') {
            hideLoginModal();
            showUserSection(data);
            showToast(`Bienvenue ${data.name}!`, 'success');
            
            // Store role after successful login
            currentUserRole = data.role;
            
            // Show/hide Users tab based on role
            const usersTab = document.querySelector('[data-view="users"]');
            if (usersTab) {
                if (currentUserRole === 'admin') {
                    usersTab.style.display = '';
                } else {
                    usersTab.style.display = 'none';
                }
            }
        } else {
            if (errorEl) {
                errorEl.textContent = data.error || 'Erreur de connexion';
                errorEl.style.display = 'block';
            }
        }
    } catch (e) {
        if (errorEl) {
            errorEl.textContent = 'Erreur de connexion';
            errorEl.style.display = 'block';
        }
    }
}

async function doLogout() {
    try {
        await fetch(API_BASE + 'auth.php?logout=1', { method: 'POST' });
        
        // Clear user section
        const section = document.getElementById('userSection');
        if (section) section.style.display = 'none';
        
        // Clear role
        currentUserRole = null;
        
        // Hide Users tab on logout
        const usersTab = document.querySelector('[data-view="users"]');
        if (usersTab) {
            usersTab.style.display = 'none';
        }
        
        // Force show login modal
        const loginModal = document.getElementById('loginModal');
        if (loginModal) {
            loginModal.classList.add('active');
        }
        
    } catch (e) {
        console.error('Logout failed:', e);
    }
}

// Event listeners login
document.getElementById('doLogin')?.addEventListener('click', doLogin);
document.getElementById('logoutBtn')?.addEventListener('click', doLogout);
document.getElementById('closeLoginModal')?.addEventListener('click', hideLoginModal);
document.getElementById('loginModal')?.addEventListener('click', (e) => {
    if (e.target.id === 'loginModal') hideLoginModal();
});
document.getElementById('loginPassword')?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') doLogin();
});