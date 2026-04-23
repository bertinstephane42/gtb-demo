# Protocoles Industriels Simulators

## BACnet

### Overview
BACnet (Building Automation and Control Network) est un protocole de communication standardisé (ASHRAE 135) pour les systèmes GTB.

### Éléments simulés

#### Object Types
- **AI** (Analog Input) : Capteur (température, CO2, puissance)
- **AO** (Analog Output) : Actionneur (VAV, registre)
- **BI** (Binary Input) : Entrée tout-ou-rien (détection présence)
- **BO** (Binary Output) : Sortie tout-ou-rien (relais)
- **AV** (Analog Value) : Valeur analogique interne
- **BV** (Binary Value) : Valeur binaire interne

#### Instance
Numéro unique identifiant l'objet sur le réseau.

#### Adressage
- BACnet/IP : `10.0.1.xx`
- Port UDP : 47808

### Logs simulés

```
[BACNET] READ AI:601 PV → 650 ppm
[BACNET] WRITE AO:201 PV = 23.5 °C
[BACNET] READ BI:701 PV → ACTIVE
[BACNET] WRITE AV:401 PV = 52.3 kW
```

## KNX

### Overview
KNX est le standard européen pour l'automatisation du bâtiment.

### Éléments simulés

#### Group Address
Format : `area/line/group`
- Area : zone (1 = éclairage, 2 = HVAC)
- Line : ligne (0-15)
- Group : groupe (0-255)

Exemples :
- `1/1/1` : Éclairage Bureau 201
- `1/1/2` : Éclairage Bureau 202
- `2/1/1` : Ventilation

#### Data Point Types (DPT)
- **DPT 1.001** : Switch (0/1)
- **DPT 5.001** : Percentage (0-100%)
- **DPT 9.001** : Temperature (float)
- **DPT 9.002** : Humidity (relative)

### Logs simulés

```
[KNX] GroupValueWrite 1/1/1 = 80%
[KNX] GroupValueRead 1/1/2
[KNX] GroupValueWrite 2/1/1 = 65%
```

## MQTT

### Overview
MQTT (Message Queuing Telemetry Transport) est un protocole léger pub/sub pour l'IoT.

### Éléments simulés

#### Topics
Structure : `building/{floor}/{device}`

Exemples :
- `building/et1/vav-201` : Température VAV 201
- `building/et1/co2-201` : CO2 Bureau 201
- `building/rdc/cta-01` :CTA

#### QoS (Quality of Service)
- QoS 0 : At most once
- QoS 1 : At least once
- QoS 2 : Exactly once

### Logs simulés

```
[MQTT] PUB building/et1/co2-201 → 650 ppm
[MQTT] SUB building/et1/# 
[MQTT] PUB building/tableau/pwr-total → 52.3 kW
```

## Comparaison Protocoles

| Critère | BACnet | KNX | MQTT |
|--------|-------|-----|------|
| Type | Client/Server | Client/Server | Pub/Sub |
| Transport | IP/Serial | TP | IP |
| Complexité | Haute | Moyenne | Faible |
| Standard | ASHRAE | CENELEC | OASIS |
| Usage | Tertiaire | Résidentiel | IoT |

## Configuration Réseau Simulée

```
Sous-réseau : 10.0.1.0/24
Passerelle : 10.0.1.1
DNS : 10.0.1.53

Équipements par protocole :
- BACnet : 10.0.1.10 - 10.0.1.60
- KNX : tunnel IP (10.0.1.70)
- MQTT : 10.0.1.80 (broker)
```
