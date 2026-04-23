// Sección por programa con fila de tarjetas
function ProgramSection({ program, graduates, onOpen }) {
  if (graduates.length === 0) return null;
  return (
    <section className="program" data-screen-label={program.shortName}>
      <div className="program__header">
        <h2 className="program__title">{program.name}</h2>
        <div className="program__subtitle">
          <span>Graduados con Honores</span>
          <span className="program__medal">
            <window.IconMedal size={22} color="#fff" />
          </span>
          <span className="program__count">{graduates.length}</span>
        </div>
      </div>
      <div className="program__grid">
        {graduates.map((g) => (
          <window.GraduateCard key={g.id} g={g} onClick={() => onOpen(g)} />
        ))}
      </div>
    </section>
  );
}

window.ProgramSection = ProgramSection;
