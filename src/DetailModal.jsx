// Modal con ficha detallada del alumno
function DetailModal({ graduate, program, onClose }) {
  const [copied, setCopied] = React.useState(false);
  const t = window.useT();
  const countryName = window.useCountryName();

  React.useEffect(() => {
    const onKey = (e) => { if (e.key === "Escape") onClose(); };
    window.addEventListener("keydown", onKey);
    document.body.style.overflow = "hidden";
    return () => {
      window.removeEventListener("keydown", onKey);
      document.body.style.overflow = "";
    };
  }, [onClose]);

  const handleShare = async () => {
    const url = `${location.origin}${location.pathname}#alumno=${graduate.id}`;
    const shareData = {
      title: `${graduate.name} — ${t("shareTitle")}`,
      text: `${graduate.name} ${t("shareText")} ${program.shortName} — ENEB`,
      url,
    };
    try {
      if (navigator.share) {
        await navigator.share(shareData);
      } else {
        await navigator.clipboard.writeText(url);
        setCopied(true);
        setTimeout(() => setCopied(false), 1800);
      }
    } catch (_) {}
  };

  const badgeIcon = (icon) =>
    icon === "diploma" ? window.IconDiploma : icon === "medal" ? window.IconMedal : window.IconFlame;

  return (
    <div className="modal-backdrop" onClick={onClose} role="dialog" aria-modal="true">
      <div className="modal" onClick={(e) => e.stopPropagation()}>
        <button className="modal__close" onClick={onClose} aria-label={t("close")}>
          <window.IconClose size={22} color="#fff" />
        </button>

        <div className="modal__hero">
          <div className="modal__photo">
            <window.Avatar size={220} tone="dark" gender={graduate.gender} photo={graduate.photo} />
          </div>
          <div className="modal__hero-text">
            <div className="modal__kicker">{t("classOf")} {graduate.year}{graduate.country && graduate.country !== "unknown" ? ` · ${countryName(graduate.country)}` : ""}</div>
            <h2 className="modal__name">{graduate.name}</h2>
            <div className="modal__program">{program.name}</div>
            <div className="modal__honor-row">
              <span className="modal__pill modal__pill--gold">
                <window.IconGrade size={14} color="#a30911" />
                {t("honor_" + graduate.honor, graduate.honor)}
              </span>
              <span className="modal__pill">{ t("averageGrade") } {graduate.grade}</span>
            </div>
          </div>
        </div>

        {graduate.badges.length > 0 && (
          <div className="modal__section">
            <div className="modal__section-title">{t("achievements")}</div>
            <div className="modal__badges">
              {graduate.badges.map((b) => {
                const Icon = badgeIcon(b.icon);
                return (
                  <div key={b.id} className="modal__badge-card">
                    <div className="modal__badge-icon">
                      <Icon size={28} color="#fff" />
                    </div>
                    <div className="modal__badge-label">{b.label}</div>
                  </div>
                );
              })}
            </div>
          </div>
        )}

        <div className="modal__section">
          <div className="modal__section-title">{t("graduationMessage")}</div>
          <p className="modal__message">“{graduate.message}”</p>
        </div>

        <div className="modal__footer">
          <button className="btn btn--ghost" onClick={handleShare}>
            <window.IconShare size={16} color="#fff" />
            {copied ? t("linkCopied") : t("shareProfile")}
          </button>
          <button className="btn btn--solid" onClick={onClose}>{t("close")}</button>
        </div>
      </div>
    </div>
  );
}

window.DetailModal = DetailModal;
