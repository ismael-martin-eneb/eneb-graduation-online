// =======================================================
// ENEB · Graduación Online — i18n (ES / EN / PT)
// =======================================================

window.TRANSLATIONS = {
  es: {
    searchPlaceholder: "Encontrarme…",
    searchLabel: "Buscar graduado",
    heroEyebrow: "Graduación Online 2026",
    heroTitle1: "Hoy celebramos",
    heroTitle2: "vuestro esfuerzo.",
    heroLead:
      "Encuentra tu nombre, descubre a tus compañeros y comparte con el mundo el momento en que todo lo aprendido se convierte en un nuevo comienzo.",
    loading: "Cargando graduados…",
    noResultsBefore: "No hemos encontrado a nadie con ",
    noResultsAfter: ". Prueba con otro nombre o país.",
    footerBrand: "ENEB · Escuela de Negocios",
    footerMeta: "Graduación Online · Promoción 2026",
    graduatesWithHonors: "Graduados con Honores",
    viewProfile: "Ver ficha de",
    close: "Cerrar",
    classOf: "Promoción",
    achievements: "Reconocimientos",
    graduationMessage: "Mensaje de graduación",
    linkCopied: "Enlace copiado ✓",
    shareProfile: "Compartir ficha",
    averageGrade: "Nota media",
    shareTitle: "Graduado/a ENEB",
    shareText: "se ha graduado en",
  },
  en: {
    searchPlaceholder: "Find me…",
    searchLabel: "Search graduate",
    heroEyebrow: "Online Graduation 2026",
    heroTitle1: "Today we celebrate",
    heroTitle2: "your effort.",
    heroLead:
      "Find your name, discover your classmates and share with the world the moment when everything you have learned becomes a new beginning.",
    loading: "Loading graduates…",
    noResultsBefore: "No one found for ",
    noResultsAfter: ". Try a different name or country.",
    footerBrand: "ENEB · Business School",
    footerMeta: "Online Graduation · Class of 2026",
    graduatesWithHonors: "Graduates with Honors",
    viewProfile: "View profile of",
    close: "Close",
    classOf: "Class of",
    achievements: "Achievements",
    graduationMessage: "Graduation Message",
    linkCopied: "Link copied ✓",
    shareProfile: "Share profile",
    averageGrade: "Average grade",
    shareTitle: "ENEB Graduate",
    shareText: "has graduated in",
  },
  pt: {
    searchPlaceholder: "Encontrar-me…",
    searchLabel: "Pesquisar graduado",
    heroEyebrow: "Graduação Online 2026",
    heroTitle1: "Hoje celebramos",
    heroTitle2: "o vosso esforço.",
    heroLead:
      "Encontra o teu nome, descobre os teus colegas e partilha com o mundo o momento em que tudo o que aprendeste se torna um novo começo.",
    loading: "A carregar graduados…",
    noResultsBefore: "Ninguém encontrado para ",
    noResultsAfter: ". Tenta outro nome ou país.",
    footerBrand: "ENEB · Escola de Negócios",
    footerMeta: "Graduação Online · Turma 2026",
    graduatesWithHonors: "Graduados com Distinção",
    viewProfile: "Ver perfil de",
    close: "Fechar",
    classOf: "Turma",
    achievements: "Reconhecimentos",
    graduationMessage: "Mensagem de graduação",
    linkCopied: "Link copiado ✓",
    shareProfile: "Partilhar perfil",
    averageGrade: "Nota média",
    shareTitle: "Graduado/a ENEB",
    shareText: "graduou-se em",
  },
};

// React context — valor por defecto "es"
window.LangContext = React.createContext("es");

// Hook reutilizable: devuelve función t(key)
window.useT = function () {
  var lang = React.useContext(window.LangContext);
  return function (key) {
    var dict = window.TRANSLATIONS[lang] || window.TRANSLATIONS["es"];
    return dict[key] !== undefined ? dict[key] : key;
  };
};
