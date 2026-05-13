var FALLBACK_PHOTOS = [
  "images/1-girl.png", "images/2-boy.png",  "images/3-girl.png",
  "images/4-boy.png",  "images/5-girl.png", "images/6-boy.png",
  "images/7-boy.png",  "images/8-girl.png", "images/9-girl.png",
  "images/10-boy.png"
];

function App() {
  const [query, setQuery] = React.useState("");
  const [openGraduate, setOpenGraduate] = React.useState(null);
  const [scrollY, setScrollY] = React.useState(0);
  const [programs, setPrograms] = React.useState([]);
  const [graduates, setGraduates] = React.useState([]);
  const [loading, setLoading] = React.useState(true);
  const [lang, setLang] = React.useState("es");

  const t = (key) => {
    var dict = window.TRANSLATIONS[lang] || window.TRANSLATIONS["es"];
    return dict[key] !== undefined ? dict[key] : key;
  };

  // Referencia mutable para acceder a graduates desde el listener de hashchange
  const graduatesRef = React.useRef([]);
  React.useEffect(() => { graduatesRef.current = graduates; }, [graduates]);

  // Cargar datos desde la API
  React.useEffect(() => {
    fetch((window.API_BASE || "") + "/api/graduates.php")
      .then(function(r) { return r.json(); })
      .then(function(data) {
        var photoIdx = 0;
        var grads = (data.graduates || []).map(function(g) {
          if (!g.photo) {
            return Object.assign({}, g, {
              photo: FALLBACK_PHOTOS[photoIdx++ % FALLBACK_PHOTOS.length]
            });
          }
          return g;
        });
        setPrograms(data.programs || []);
        setGraduates(grads);
      })
      .catch(function() { /* la UI muestra estado vacío */ })
      .finally(function() { setLoading(false); });
  }, []);

  // Abrir ficha por hash tras cargar datos
  React.useEffect(() => {
    if (graduates.length === 0) return;
    const m = location.hash.match(/alumno=([^&]+)/);
    if (m) {
      const g = graduates.find(function(x) { return x.id === decodeURIComponent(m[1]); });
      if (g) setOpenGraduate(g);
    }
  }, [graduates]);

  // Listener de hashchange (para navegación posterior a la carga)
  React.useEffect(() => {
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
  React.useEffect(() => {
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
        const countryName = t("country_" + g.country, g.country);
        return g.name.toLowerCase().includes(q) ||
               countryName.toLowerCase().includes(q) ||
               g.country.toLowerCase().includes(q);
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
    <window.LangContext.Provider value={lang}>
    <div className="app">
      <div
        className="app__bg"
        style={{ transform: `translate3d(0, ${scrollY * -0.15}px, 0)` }}
      >
        <window.BackgroundFX />
      </div>
      <div
        className="app__bg app__bg--slow"
        style={{ transform: `translate3d(0, ${scrollY * -0.05}px, 0)` }}
        aria-hidden="true"
      />

      <header className="topbar">
        {/* <button className="iconbtn" aria-label="Menú">
          <window.IconList size={28} color="#fff" />
        </button> */}
        <div className="brand"><img src="https://eneb.es/wp-content/uploads/2021/01/eneb-logo.png" alt="ENEB"></img></div>
        <label className="search">
          <window.IconSearch size={18} color="#fff" />
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
          <window.ProgramSection
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
        <window.DetailModal graduate={openGraduate} program={program} onClose={handleClose} />
      )}
    </div>
    </window.LangContext.Provider>
  );
}

window.App = App;
