// Mock data para el prototipo. En producción vendrá de la BBDD
// (sync desde Moodle → Postgres/Supabase → API /api/graduates).

window.ENEB_PROGRAMS = [
  {
    id: "big-data",
    name: "Máster en Big Data y Business Intelligence",
    shortName: "Big Data & BI",
    promo: "Promoción 2026",
  },
  {
    id: "pm-digital",
    name: "Máster en Project Management + Máster en Digital Business",
    shortName: "PM + Digital Business",
    promo: "Promoción 2026",
  },
  {
    id: "mba",
    name: "MBA - Máster en Administración y Dirección de Empresas",
    shortName: "MBA",
    promo: "Promoción 2026",
  },
  {
    id: "rrhh",
    name: "Máster en Dirección y Gestión de Recursos Humanos",
    shortName: "RRHH",
    promo: "Promoción 2026",
  },
  {
    id: "marketing",
    name: "Máster en Marketing Digital y Social Media",
    shortName: "Marketing Digital",
    promo: "Promoción 2026",
  },
  {
    id: "finanzas",
    name: "Máster en Dirección Financiera y Contable",
    shortName: "Finanzas",
    promo: "Promoción 2026",
  },
];

const COUNTRIES = [
  "España", "México", "Colombia", "Argentina", "Chile", "Perú",
  "Ecuador", "Venezuela", "Uruguay", "Paraguay", "Bolivia", "Costa Rica",
  "Panamá", "República Dominicana", "Guatemala", "Honduras", "El Salvador",
  "Estados Unidos", "Brasil", "Portugal", "Andorra", "Marruecos",
];

const FEMALE_NAMES = [
  "María", "Carmen", "Laura", "Ana", "Isabel", "Sofía", "Lucía", "Elena",
  "Paula", "Claudia", "Valeria", "Camila", "Daniela", "Andrea", "Patricia",
];

const MALE_NAMES = [
  "Carlos", "Javier", "Alejandro", "Miguel", "Pablo", "David", "Diego",
  "Fernando", "Rodrigo", "Sergio", "Álvaro", "Jorge", "Manuel", "Luis",
  "Adrián", "Mateo", "Hugo", "Raúl", "Óscar", "Ignacio", "Gonzalo",
];

const LAST_NAMES = [
  "García", "Rodríguez", "Martínez", "López", "González", "Pérez", "Sánchez",
  "Fernández", "Gómez", "Díaz", "Torres", "Ramírez", "Flores", "Herrera",
  "Medina", "Castro", "Vargas", "Ortega", "Ruiz", "Morales", "Jiménez",
  "Álvarez", "Romero", "Navarro", "Silva", "Reyes", "Cruz", "Mendoza",
];

const HONORS = [
  { label: "Cum Laude", weight: 3 },
  { label: "Matrícula de Honor", weight: 1 },
  { label: "Sobresaliente", weight: 5 },
  { label: "Notable Alto", weight: 8 },
];

// seeded random (simple LCG) for stable data across reloads
function seededRandom(seed) {
  let s = seed;
  return () => {
    s = (s * 9301 + 49297) % 233280;
    return s / 233280;
  };
}

function pick(rng, arr) {
  return arr[Math.floor(rng() * arr.length)];
}

function weightedPick(rng, items) {
  const total = items.reduce((a, b) => a + b.weight, 0);
  let r = rng() * total;
  for (const it of items) {
    r -= it.weight;
    if (r <= 0) return it.label;
  }
  return items[0].label;
}

function genGraduate(rng, idx, programId) {
  const gender = rng() < 0.5 ? "f" : "m";
  const first = pick(rng, gender === "f" ? FEMALE_NAMES : MALE_NAMES);
  const last1 = pick(rng, LAST_NAMES);
  const last2 = pick(rng, LAST_NAMES);
  const name = `${first} ${last1} ${last2}`;
  const country = pick(rng, COUNTRIES);
  const honor = weightedPick(rng, HONORS);
  // Badges: solo Class President y Graduate Cum Laude (según brief del usuario)
  const badges = [];
  if (honor === "Cum Laude" || honor === "Matrícula de Honor") {
    badges.push({ id: "cum-laude", label: "Graduate Cum Laude", icon: "diploma" });
  }
  if (rng() < 0.08) {
    badges.push({ id: "class-president", label: "Class President", icon: "medal" });
  }
  // Nota media entre 7.5 y 10
  const grade = (7.5 + rng() * 2.5).toFixed(2);
  return {
    id: `${programId}-${idx}`,
    name,
    country,
    programId,
    honor,
    grade,
    badges,
    gender,
    year: 2026,
    message: pick(rng, [
      "Gracias a ENEB por este camino que hoy me transforma profesionalmente.",
      "Orgulloso de formar parte de esta promoción. ¡A por todas!",
      "Un año de esfuerzo que hoy se convierte en el inicio de algo grande.",
      "Hoy cierro una etapa y abro la puerta a nuevas oportunidades.",
      "Gracias a mis profesores, compañeros y familia por el apoyo.",
    ]),
  };
}

// ~45 graduados distribuidos por programa
const COUNTS = {
  "big-data": 9,
  "pm-digital": 9,
  "mba": 10,
  "rrhh": 6,
  "marketing": 6,
  "finanzas": 5,
};

const rng = seededRandom(42);
window.ENEB_GRADUATES = [];
for (const prog of window.ENEB_PROGRAMS) {
  const n = COUNTS[prog.id] || 6;
  for (let i = 0; i < n; i++) {
    window.ENEB_GRADUATES.push(genGraduate(rng, i, prog.id));
  }
}
const GIRL_PHOTOS = ["images/1-girl.png", "images/3-girl.png", "images/5-girl.png", "images/8-girl.png", "images/9-girl.png"];
const BOY_PHOTOS  = ["images/2-boy.png",  "images/4-boy.png",  "images/6-boy.png",  "images/7-boy.png",  "images/10-boy.png"];
let girlIdx = 0, boyIdx = 0;
window.ENEB_GRADUATES.forEach((g) => {
  if (g.gender === "f") {
    g.photo = GIRL_PHOTOS[girlIdx++ % GIRL_PHOTOS.length];
  } else {
    g.photo = BOY_PHOTOS[boyIdx++ % BOY_PHOTOS.length];
  }
});
