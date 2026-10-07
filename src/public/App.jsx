import { useState, useEffect, useRef } from "react";
import { LangContext, TRANSLATIONS, resolveCountryName } from "./i18n.js";
import BackgroundFX from "./components/BackgroundFX.jsx";
import ProgramSection from "./components/ProgramSection.jsx";
import DetailModal from "./components/DetailModal.jsx";
import { IconSearch } from "./components/Icons.jsx";

/** Base de la API: vacío = mismo origen (en dev Vite hace proxy de /api). */
const API_BASE = import.meta.env.VITE_API_BASE || "";
/** Foto por defecto para alumnos sin foto subida. */
const FALLBACK_PHOTO = "/images/user.png";

/** Traductor simple para el propio App (los hijos usan el hook useT). */
const makeT = (lang) => (key) => {
  const dict = TRANSLATIONS[lang] || TRANSLATIONS.es;
  return dict[key] !== undefined ? dict[key] : key;
};

/**
 * Vista pública de la graduación online: hero, buscador, selector de idioma,
 * secciones por programa y ficha de detalle (deep-link con #alumno=ID).
 */
export default function App() {
  const [query, setQuery] = useState("");
  const [openGraduate, setOpenGraduate] = useState(null);
  const [scrollY, setScrollY] = useState(0);
  const [programs, setPrograms] = useState([]);
  const [graduates, setGraduates] = useState([]);
  const [loading, setLoading] = useState(true);
  const [lang, setLang] = useState("es");

  const t = makeT(lang);

  // Referencia mutable para acceder a graduates desde el listener de hashchange
  const graduatesRef = useRef([]);
  useEffect(() => { graduatesRef.current = graduates; }, [graduates]);

  // Cargar datos desde la API
  useEffect(() => {
    fetch(API_BASE + "/api/graduates.php")
      .then((r) => r.json())
      .then((data) => {
        // Alumnos sin foto usan el avatar por defecto (cadena, no array)
        const grads = (data.graduates || []).map((g) => (g.photo ? g : { ...g, photo: FALLBACK_PHOTO }));
        setPrograms(data.programs || []);
        setGraduates(grads);
      })
      .catch(() => { /* la UI muestra estado vacío */ })
      .finally(() => setLoading(false));
  }, []);

  // Abrir ficha por hash tras cargar datos
  useEffect(() => {
    if (graduates.length === 0) return;
    const m = location.hash.match(/alumno=([^&]+)/);
    if (m) {
      const g = graduates.find(function(x) { return x.id === decodeURIComponent(m[1]); });
      if (g) setOpenGraduate(g);
    }
  }, [graduates]);

  // Listener de hashchange (para navegación posterior a la carga)
  useEffect(() => {
    const applyHash = function() {
      const m = location.hash.match(/alumno=([^&]+)/);
      if (m) {
        const g = graduatesRef.current.find(function(x) { return x.id === decodeURIComponent(m[1]); });
        if (g) setOpenGraduate(g);
      }
    };
    window.addEventListener("hashchange", applyHash);
    return function() { window.removeEventListener("hashchange", applyHash); };
  }, []);

  // Paralaje del fondo
  useEffect(() => {
    let raf = 0;
    const onScroll = () => {
      if (raf) return;
      raf = requestAnimationFrame(() => {
        setScrollY(window.scrollY);
        raf = 0;
      });
    };
    window.addEventListener("scroll", onScroll, { passive: true });
    return () => window.removeEventListener("scroll", onScroll);
  }, []);

  const q = query.trim().toLowerCase();
  const filtered = q
    ? graduates.filter(function(g) {
        const resolvedCountry = resolveCountryName(g.country, lang);
        return g.name.toLowerCase().includes(q) ||
               resolvedCountry.toLowerCase().includes(q) ||
               (g.country ? g.country.toLowerCase().includes(q) : false);
      })
    : graduates;

  const byProgram = {};
  for (const g of filtered) {
    if (!byProgram[g.programId]) byProgram[g.programId] = [];
    byProgram[g.programId].push(g);
  }

  const program = openGraduate
    ? programs.find(function(p) { return p.id === openGraduate.programId; })
    : null;

  const handleClose = () => {
    setOpenGraduate(null);
    if (location.hash.includes("alumno=")) {
      history.replaceState(null, "", location.pathname + location.search);
    }
  };

  return (
    <LangContext.Provider value={lang}>
    <div className="app">
      <div
        className="app__bg"
        style={{ transform: `translate3d(0, ${scrollY * -0.15}px, 0)` }}
      >
        <BackgroundFX />
      </div>
      <div
        className="app__bg app__bg--slow"
        style={{ transform: `translate3d(0, ${scrollY * -0.05}px, 0)` }}
        aria-hidden="true"
      />

      <header className="topbar">
        <div className="brand"><img src="https://eneb.es/wp-content/uploads/2021/01/eneb-logo.png" alt="ENEB"></img></div>
        <label className={"search" + (query ? " search--active" : "")}>
          <IconSearch size={18} color="#fff" />
          <input
            type="text"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder={t("searchPlaceholder")}
            aria-label={t("searchLabel")}
          />
        </label>
        <div className="lang-switcher" role="group" aria-label="Idioma / Language">
          {["es", "en", "pt"].map((l) => (
            <button
              key={l}
              className={"lang-btn" + (lang === l ? " lang-btn--active" : "")}
              onClick={() => setLang(l)}
              aria-pressed={lang === l}
            >
              {l.toUpperCase()}
            </button>
          ))}
        </div>
        <select
          className="lang-switcher-mobile"
          value={lang}
          onChange={(e) => setLang(e.target.value)}
          aria-label="Idioma / Language"
        >
          <option value="es">ES</option>
          <option value="en">EN</option>
          <option value="pt">PT</option>
        </select>
      </header>

      <main className="content">
        <section className="hero" data-screen-label="00 Hero">
          <div className="hero__eyebrow">{t("heroEyebrow")}</div>
          <h1 className="hero__title">
            {t("heroTitle1")}<br />
            <span className="hero__title--accent">{t("heroTitle2")}</span>
          </h1>
          <p className="hero__lead">{t("heroLead")}</p>
        </section>

        {loading && (
          <div className="empty" style={{ opacity: 0.7 }}>{t("loading")}</div>
        )}

        {!loading && q && filtered.length === 0 && (
          <div className="empty">
            {t("noResultsBefore")}<strong>"{query}"</strong>{t("noResultsAfter")}
          </div>
        )}

        {programs.map(function(p) { return (
          <ProgramSection
            key={p.id}
            program={p}
            graduates={byProgram[p.id] || []}
            onOpen={function(g) {
              setOpenGraduate(g);
              history.replaceState(null, "", "#alumno=" + g.id);
            }}
          />
        ); })}

        <footer className="foot">
          <div className="foot__row">
            <div className="foot__brand">{t("footerBrand")}</div>
            <div className="foot__meta">{t("footerMeta")}</div>
          </div>
        </footer>
      </main>

      {openGraduate && program && (
        <DetailModal graduate={openGraduate} program={program} onClose={handleClose} />
      )}
    </div>
    </LangContext.Provider>
  );
}
