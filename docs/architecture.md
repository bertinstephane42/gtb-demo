# Architecture SCADA GTB

## Vue d'ensemble

```
┌─────────────────────────────────────────────────────────────┐
│                    FRONTEND (HTML/JS/CSS)                   │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐ │
│  │  Vue     │  │  Vue     │  │  Vue     │  │  Graphes │ │
│  │  Dashboard│  │  Étage   │  │  Events  │  │ Temps   │ │
│  └──────────┘  └──────────┘  └──────────┘  └──────────┘ │
└────────────────────────────┬────────────────────────────────────┘
                         │ fetch() AJAX (polling 1s)
                         ▼
┌─────────────────────────────────────────────────────────────┐
│                      API REST PHP                          │
│  ┌──────────────┐  ┌──────────────┐  ┌────────────────┐  │
│  │ getDevices   │  │ writeDevice  │  │ historian      │  │
│  │   .php       │  │   .php       │  │   .php         │  │
│  └──────┬───────┘  └──────┬───────┘  └───────┬────────┘  │
│         │                 │                 │            │
│         └────────────┬─────┴─────────────────┘            │
│                      │                                     │
│         ┌───────────▼─────────────┐                       │
│         │    simulate.php         │                       │
│         │  - Latence (50-300ms)   │                       │
│         │  - Perte paquet (~5%)    │                       │
│         │  - Logique GTB          │                       │
│         └─────────────────────────┘                       │
└────────────────────────────┬────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                     DONNÉES (JSON)                       │
│  ┌────────────────┐  ┌────────────────┐  ┌─────────────┐ │
│  │  devices.json  │  │ history.json  │  │ events.json │ │
│  │ (18 équipements)│  │ (1000 points) │  │ (200 events)│ │
│  └────────────────┘  └────────────────┘  └─────────────┘│
└─────────────────────────────────────────────────────────────┘
```

## Modèle de données

### Device (JSON)

```json
{
  "id": "VAV-201",
  "name": "VAV-201",
  "description": "VAV Bureau 201",
  "protocol": "bacnet",
  "instance": 201,
  "addr": "10.0.1.20",
  "floor": "ET1",
  "category": "CVC",
  "tag": {
    "type": "AO",
    "value": 22.5,
    "unit": "°C",
    "min": 15,
    "max": 30,
    "alarms": { "HH": 28, "H": 26, "L": 18, "LL": 16 },
    "quality": "GOOD",
    "ts": 1234567890
  },
  "state": "AUTO"
}
```

## Catégories d'équipements

| Catégorie | Description | Exemples |
|-----------|------------|----------|
| CVC | Chauffage Ventilation Climatisation | CTA, VAV, Groupe Froid, Chaudière |
| ENERGIE | Mesure Puissance | PWR-TOTAL, PWR-ETAGE1, PWR-ETAGE2 |
| CONFORT | Qualité Air, Présence | CO2, OCC |
| ECLAIRAGE | Gestion Éclairage KNX | LUM-201, LUM-202 |
| SECURITE | Détection Incendie/Fuite | LEAK-01, ALARM-TECH |

## Protocoles simulés

### BACnet (Building Automation and Control Network)
- Protocole standardisé pour automates bâtiments
- Object types : AI, AO, BI, BO, AV, BV
- Instance : numéro d'objet unique
- Adressage IP : BACnet/IP

### KNX (Konnex)
- Standard européen domotique/bâtiment
- Group Address : area/line/group (ex: 1/1/1)
- Data Point Types (DPT) pour valeurs

### MQTT
- Protocole messagerie IoT
- Topics structurés : building/{floor}/{device}
- Publish/Subscribe

## Logique GTB implémentée

### Contrôle CO2 → Ventilation
```
SI CO2 > 800 ppm
  ALORSaugmenter VAV (+2%)
```

### Délestage Énergie
```
SI Puissance Totale > 70 kW
  ALORS éclairage = OFF (si AUTO)
```

### Régulation PID (simulée)
- Variation sinusoidale température
- Dérive bruits aléatoires

## Polling & Temps Réel

- **Intervalle** : 1000ms
- **Timeout** : 5000ms
- **Retry** : automatique après perte

## Historisation

- **Fréquence** : toutes les 10 secondes
- **Capacité** : 1000 points (rolling)
- **Données** : id, name, floor, value, unit, ts