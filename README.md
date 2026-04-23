# SCADA GTB - Simulation Web

## 🔧 Instructions de lancement

```bash
cd /home/myx/Documents/gtb
php -S localhost:8080
```

Puis ouvrir : `http://localhost:8080`

## 🏗️ Architecture

```
gtb/
├── index.html          # Interface SCADA principale
├── app.js             # Logique frontend + polling
├── styles.css        # Thème SCADA industriel
├── api/
│   ├── getDevices.php   # Lecture équipements + alarmes
│   ├── writeDevice.php  # Écriture (BACnet/KNX/MQTT)
│   ├── historian.php    # API historian
│   └── simulate.php     # Simulation réseau
├── data/
│   ├── devices.json    # Configuration équipements
│   ├── history.json   # Données historisées
│   └── events.json   # Journal des événements
├── docs/
│   ├── architecture.md
│   └── protocols.md
└── README.md
```

## 🎯 Fonctionnalités

- **Polling AJAX** 1s pour pseudo temps réel
- **Simulation réseau** : latence (50-300ms), perte paquet (~5%)
- **Protocoles simulés** : BACnet, KNX, MQTT avec logs
- **Gestion alarmes** : HH/H/L/LL avec ACK
- **Logique GTB** :
  - CO₂ > 800 → augmentation ventilation
  - Puissance > seuil → délestage éclairage
  - Régulation température PID simulée
- **Historian** :rolling buffer 1000 points
- **Graphiques** : Canvas JS temps réel

## 🏢 Équipements simulés

| Section | Équipements |
|---------|-------------|
| CVC | CTA-01, VAV-201, VAV-202, VAV-203, GROUPE-FROID, CHAUDIERE |
| Énergie | PWR-TOTAL, PWR-ETAGE1, PWR-ETAGE2 |
| Confort | CO2-201, CO2-202, OCC-201, OCC-202 |
| Éclairage | LUM-201, LUM-202, LUM-203 |
| Sécurité | LEAK-01, ALARM-TECH |

## 🚨 Alarmes

- **HH** (Rouge clignotant) : Critique haut
- **H** (Orange) : Warning haut  
- **L** (Jaune) : Warning bas
- **LL** (Rouge) : Critique bas
- **NORMAL** (Vert) : Fonctionnement normal

Cliquer sur un équipement pour modifier sa valeur.