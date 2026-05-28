// Tarjeta compacta del alumno (la que aparece en las filas)
function GraduateCard({ g, onClick }) {
  const t = window.useT();
  const hasBadges = g.badges && g.badges.length > 0;
  return (
    <button
      type="button"
      className="card"
      onClick={onClick}
      aria-label={`${t("viewProfile")} ${g.name}`}
    >
      <div className="card__photo">
        <window.Avatar gender={g.gender} size={101} photo={g.photo} />
      </div>
      <div className="card__body">
        <div className="card__name">{g.name}</div>
        {g.country && g.country !== "unknown" && g.country !== "" && (
          <div className="card__country">
            <window.IconGlobe size={14} color="rgba(255,255,255,0.85)" />
            <span>{t("country_" + g.country, g.country)}</span>
          </div>
        )}
      </div>
      {hasBadges && (
        <div className="card__badges" aria-hidden="true">
          {g.badges.slice(0, 2).map((b) => {
            const Icon = b.icon === "diploma" ? window.IconDiploma : window.IconMedal;
            return (
              <span key={b.id} className="card__badge" title={b.label}>
                <Icon size={14} color="#fff" />
              </span>
            );
          })}
        </div>
      )}
    </button>
  );
}

window.GraduateCard = GraduateCard;
