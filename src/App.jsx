function App() {
  const [query, setQuery] = React.useState("");
  const [openGraduate, setOpenGraduate] = React.useState(null);
  const [scrollY, setScrollY] = React.useState(0);

  // Abrir ficha por hash (share link)
  React.useEffect(() => {
    const applyHash = () => {
      const m = location.hash.match(/alumno=([^&]+)/);
      if (m) {
        const g = window.ENEB_GRADUATES.find((x) => x.id === decodeURIComponent(m[1]));
        if (g) setOpenGraduate(g);
      }
    };
    applyHash();
    window.addEventListener("hashchange", applyHash);
    return () => window.removeEventListener("hashchange", applyHash);
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
    ? window.ENEB_GRADUATES.filter(
        (g) =>
          g.name.toLowerCase().includes(q) ||
          g.country.toLowerCase().includes(q)
      )
    : window.ENEB_GRADUATES;

  const byProgram = {};
  for (const g of filtered) {
    (byProgram[g.programId] ||= []).push(g);
  }

  const program = openGraduate
    ? window.ENEB_PROGRAMS.find((p) => p.id === openGraduate.programId)
    : null;

  const handleClose = () => {
    setOpenGraduate(null);
    if (location.hash.includes("alumno=")) {
      history.replaceState(null, "", location.pathname + location.search);
    }
  };

  return (
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
        <button className="iconbtn" aria-label="Menú">
          <window.IconList size={28} color="#fff" />
        </button>
        <div className="brand">ENEB</div>
        <label className="search">
          <window.IconSearch size={18} color="#fff" />
          <input
            type="text"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Encontrarme..."
            aria-label="Buscar graduado"
          />
        </label>
      </header>

      <main className="content">
        <section className="hero" data-screen-label="00 Hero">
          <div className="hero__eyebrow">Graduación Online 2026</div>
          <h1 className="hero__title">
            Hoy celebramos<br />
            <span className="hero__title--accent">vuestro esfuerzo.</span>
          </h1>
          <p className="hero__lead">
            Encuentra tu nombre, descubre a tus compañeros y comparte con el mundo el momento
            en el que todo lo aprendido se convierte en un nuevo comienzo.
          </p>
        </section>

        {q && filtered.length === 0 && (
          <div className="empty">
            No hemos encontrado a nadie con <strong>“{query}”</strong>. Prueba con otro nombre o país.
          </div>
        )}

        {window.ENEB_PROGRAMS.map((p) => (
          <window.ProgramSection
            key={p.id}
            program={p}
            graduates={byProgram[p.id] || []}
            onOpen={(g) => {
              setOpenGraduate(g);
              history.replaceState(null, "", `#alumno=${g.id}`);
            }}
          />
        ))}

        <footer className="foot">
          <div className="foot__row">
            <div className="foot__brand">ENEB · Escuela de Negocios</div>
            <div className="foot__meta">Graduación Online · Promoción 2026</div>
          </div>
        </footer>
      </main>

      {openGraduate && program && (
        <window.DetailModal graduate={openGraduate} program={program} onClose={handleClose} />
      )}
    </div>
  );
}

window.App = App;
