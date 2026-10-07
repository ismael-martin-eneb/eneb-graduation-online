// Avatar genérico de silueta (monocromo blanco sobre fondo rojo oscuro)
// En producción se sustituye por la foto subida por el alumno.
function Avatar({ gender: _gender, size = 128, tone = "light", photo }) {
  if (photo) {
    return (
      <img
        src={photo}
        width={size}
        height={size}
        style={{ display: "block", borderRadius: 24, objectFit: "cover", objectPosition: "top" }}
        aria-hidden="true"
      />
    );
  }

  const bg = tone === "light" ? "rgba(255,255,255,0.25)" : "rgba(163,9,17,0.55)";
  const fg = "rgba(255,255,255,0.88)";
  return (
    <svg
      viewBox="0 0 128 128"
      width={size}
      height={size}
      style={{ display: "block", borderRadius: 24 }}
      aria-hidden="true"
    >
      <rect width="128" height="128" rx="24" fill={bg} />
      <circle cx="64" cy="50" r="20" fill={fg} />
      <path
        d="M 20 128 C 20 98 38 82 64 82 C 90 82 108 98 108 128 Z"
        fill={fg}
      />
      <path
        d="M 48 82 L 64 104 L 80 82 L 80 128 L 48 128 Z"
        fill={tone === "light" ? "rgba(163,9,17,0.85)" : "rgba(0,0,0,0.35)"}
      />
    </svg>
  );
}

window.Avatar = Avatar;
