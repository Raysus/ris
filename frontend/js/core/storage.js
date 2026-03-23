const STORAGE_KEY = 'ris_app_data';

function loadRISState() {
    const savedData = localStorage.getItem(STORAGE_KEY);

    if (savedData) {
        window.RIS = JSON.parse(savedData);
        if (!window.RIS.personas) window.RIS.personas = [];

        if (!window.RIS.groupMap) {
            window.RIS.groupMap = {
                'RAYOS X': 'RX', 'TOMOGRAFÍA': 'CT', 'RESONANCIA': 'MRI',
                'ECOGRAFÍA': 'ECO', 'MAMOGRAFÍA': 'MAMO', 'DENSITOMETRÍA': 'DEXA'
            };
        }
        if (!window.RIS.tiemposPorGrupo) {
            window.RIS.tiemposPorGrupo = {
                'RAYOS X': 15, 'TOMOGRAFÍA': 20, 'RESONANCIA': 45, 'ECOGRAFÍA': 45, 'MAMOGRAFÍA': 20, 'DENSITOMETRÍA': 15
            };
        }
        if (!window.RIS.studyDuration) {
            window.RIS.studyDuration = { "RX": 15, "CT": 20, "MRI": 45, "ECO": 20, "DEFAULT": 15 };
        }
        if (!window.RIS.inventoryZero) {
            window.RIS.inventoryZero = {
                "CONTRASTE": [
                    { id: "ins_c1", nombre: "Gadolinio 15ml", stock: 50, total: 50 },
                    { id: "ins_c2", nombre: "Iodo Optiray 350", stock: 100, total: 100 },
                    { id: "ins_c3", nombre: "Suero Fisiológico 250ml", stock: 200, total: 200 }
                ],
                "FUNGIBLE": [
                    { id: "ins_f1", nombre: "Aguja 21G", stock: 500, total: 500 },
                    { id: "ins_f2", nombre: "Gasa estéril", stock: 1000, total: 1000 },
                    { id: "ins_f3", nombre: "Tebaderm", stock: 300, total: 300 },
                    { id: "ins_f4", nombre: "Llave 3 pasos", stock: 150, total: 150 }
                ],
                "PROTECCION": [
                    { id: "ins_p1", nombre: "Protector Gonadal", stock: 5, total: 5 },
                    { id: "ins_p2", nombre: "Chaleco Plomado", stock: 10, total: 10 }
                ]
            };
        }

        if (!window.RIS.config) {
            window.RIS.config = { horaInicio: '08:00:00', horaFin: '20:00:00', intervalo: '00:15:00' };
        }
        if (!window.RIS.config.clinicName) {
            window.RIS.config.clinicName = "Centro de Diagnóstico RIS PRO";
            window.RIS.config.clinicAddress = "Av. Las Araucarias 1020, Temuco, Chile";
        }

    } else {
        window.RIS = {
            agenda: [], worklist: [],

            personas: [
                { rut: "11.222.333-4", nombres: "Admin", apellidoPaterno: "Sistema", apellidoMaterno: "", fechaNacimiento: "1980-01-01", sexo: "M", email: "admin@ris.cl", telefono: "" },
                { rut: "18.194.675-K", nombres: "Raúl Antonio", apellidoPaterno: "Gutiérrez", apellidoMaterno: "Elgueta", fechaNacimiento: "1980-01-01", sexo: "M", email: "raul.gutierrez@email.com", telefono: "+56912345678" }
            ],
            users: [
                { rut: "11.222.333-4", titulo: "Admin.", roles: ["admin", "recepcion", "tecnologo", "radiologo", "transcriptor"], username: "admin", password: "123", pacsAE: "RIS_PRO_ADMIN", dragonProfile: "Admin_Profile" }
            ],

            patients: [
                { rut: "18.194.675-K", insurance: "FONASA", plan: "Tramo D" }
            ],

            doctors: ["Dr. Arriagada", "Dra. Sánchez", "Dr. Pérez", "Dra. Muñoz"],
            radiologists: ["Dr. Radiólogo Senior", "Dra. Especialista"],

            groupMap: {
                'RAYOS X': 'RX', 'TOMOGRAFÍA': 'CT', 'RESONANCIA': 'MRI',
                'ECOGRAFÍA': 'ECO', 'MAMOGRAFÍA': 'MAMO', 'DENSITOMETRÍA': 'DEXA'
            },

            tiemposPorGrupo: {
                'RAYOS X': 15, 'TOMOGRAFÍA': 20, 'RESONANCIA': 45, 'ECOGRAFÍA': 45, 'MAMOGRAFÍA': 20, 'DENSITOMETRÍA': 15
            },

            studyDuration: { "RX": 15, "CT": 20, "MRI": 45, "ECO": 20, "DEFAULT": 15 },

            inventoryZero: {
                "CONTRASTE": [
                    { id: "ins_c1", nombre: "Gadolinio 15ml", stock: 50, total: 50 },
                    { id: "ins_c2", nombre: "Iodo Optiray 350", stock: 100, total: 100 },
                    { id: "ins_c3", nombre: "Suero Fisiológico 250ml", stock: 200, total: 200 }
                ],
                "FUNGIBLE": [
                    { id: "ins_f1", nombre: "Aguja 21G", stock: 500, total: 500 },
                    { id: "ins_f2", nombre: "Gasa estéril", stock: 1000, total: 1000 },
                    { id: "ins_f3", nombre: "Tebaderm", stock: 300, total: 300 },
                    { id: "ins_f4", nombre: "Llave 3 pasos", stock: 150, total: 150 }
                ],
                "PROTECCION": [
                    { id: "ins_p1", nombre: "Protector Gonadal", stock: 5, total: 5 },
                    { id: "ins_p2", nombre: "Chaleco Plomado", stock: 10, total: 10 }
                ]
            },

            examTypes: {
                "RX": {
                    exams: {
                        "Tórax": { subs: ["Simple", "AP/Lateral"], code: "04-01-070", price: 15000 },
                        "Pelvis": { subs: ["Adulto"], code: "04-01-071", price: 18000 }
                    }
                },
                "CT": {
                    exams: {
                        "Cerebro": { subs: ["Sin Contraste", "Con Contraste"], code: "04-02-001", price: 85000 },
                        "Abdomen y Pelvis": { subs: ["Con Contraste"], code: "04-02-005", price: 120000 }
                    }
                },
                "MRI": {
                    exams: {
                        "Columna Lumbar": { subs: ["Sin Contraste"], code: "04-03-010", price: 150000 },
                        "Rodilla": { subs: ["Sin Contraste"], code: "04-03-015", price: 120000 }
                    }
                },
                "ECO": {
                    exams: {
                        "Abdominal": { subs: ["Adulto"], code: "04-04-005", price: 25000 },
                        "Mamaria": { subs: ["Bilateral"], code: "04-04-010", price: 30000 }
                    }
                }
            },
            supplies: [
                { id: 1, name: "Contraste Yodado Optiray 350", price: 25000 },
                { id: 2, name: "Kit Aguja 21G", price: 5000 },
                { id: 3, name: "Gadolinio 15ml", price: 35000 },
                { id: 4, name: "Suero Fisiológico 250ml", price: 2000 }
            ],
            supplyPacks: [
                { name: "Pack Contraste CT", items: [1, 2, 4] },
                { name: "Pack Contraste MRI", items: [3, 2, 4] }
            ],

            config: {
                horaInicio: '08:00:00',
                horaFin: '20:00:00',
                intervalo: '00:15:00',
                clinicName: "Centro de Diagnóstico RIS PRO",
                clinicAddress: "Av. Las Araucarias 1020, Temuco, Chile"
            },

            resources: [
                { id: 'RX_01', title: 'Sala Rayos 1', group: 'RAYOS X', eventColor: '#2ec4b6' },
                { id: 'RX_02', title: 'Sala Rayos 2', group: 'RAYOS X', eventColor: '#27a79a' },
                { id: 'CT_01', title: 'Scanner Multislice', group: 'TOMOGRAFÍA', eventColor: '#3b82f6' },
                { id: 'MRI_01', title: 'Resonador 1.5T', group: 'RESONANCIA', eventColor: '#8b5cf6' },
                { id: 'ECO_01', title: 'Ecografía 1', group: 'ECOGRAFÍA', eventColor: '#f59e0b' },
                { id: 'ECO_02', title: 'Ecografía 2', group: 'ECOGRAFÍA', eventColor: '#d97706' }
            ]
        };
        saveRISState();
        console.log("🆕 Base de datos RIS unificada e inicializada con Catálogos Completos");
    }
}

function saveRISState() {
    if (window.RIS) {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(window.RIS));
        window.dispatchEvent(new CustomEvent('ris_updated'));
    }
}