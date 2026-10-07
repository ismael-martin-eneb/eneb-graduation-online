// Fondo con partículas/malla para emular los "Group" del Figma
// (una estética de red + puntos sobre el gradiente rojo).
function BackgroundFX() {
  return (
    <div className="bg-fx" aria-hidden="true">
      {/* Malla superior derecha (lado del buscador/logo) */}
      <svg className="bg-mesh bg-mesh--tr" viewBox="0 0 800 800" preserveAspectRatio="xMidYMid slice">
        <defs>
          <radialGradient id="meshFadeTR" cx="70%" cy="30%" r="55%">
            <stop offset="0%" stopColor="rgba(255,255,255,0.45)" />
            <stop offset="100%" stopColor="rgba(255,255,255,0)" />
          </radialGradient>
        </defs>
        {Array.from({ length: 22 }).map((_, i) => {
          const r = 120 + i * 22;
          return (
            <ellipse
              key={i}
              cx="560" cy="220"
              rx={r} ry={r * 0.9}
              fill="none"
              stroke="url(#meshFadeTR)"
              strokeWidth="0.6"
              transform={`rotate(${i * 4} 560 220)`}
            />
          );
        })}
      </svg>

      {/* Malla inferior izquierda (forma envolvente) */}
      <svg className="bg-mesh bg-mesh--bl" viewBox="0 0 1000 1000" preserveAspectRatio="xMidYMid slice">
        <defs>
          <radialGradient id="meshFadeBL" cx="30%" cy="70%" r="60%">
            <stop offset="0%" stopColor="rgba(255,255,255,0.35)" />
            <stop offset="100%" stopColor="rgba(255,255,255,0)" />
          </radialGradient>
        </defs>
        {Array.from({ length: 28 }).map((_, i) => {
          const r = 140 + i * 28;
          return (
            <circle
              key={i}
              cx="280" cy="620"
              r={r}
              fill="none"
              stroke="url(#meshFadeBL)"
              strokeWidth="0.5"
            />
          );
        })}
      </svg>

      {/* Nube de puntos inferior derecha */}
      <svg className="bg-dots" viewBox="0 0 1000 1000" preserveAspectRatio="xMidYMid slice">
        <defs>
          <radialGradient id="dotFade" cx="75%" cy="70%" r="45%">
            <stop offset="0%" stopColor="rgba(255,255,255,0.55)" />
            <stop offset="100%" stopColor="rgba(255,255,255,0)" />
          </radialGradient>
        </defs>
        {Array.from({ length: 320 }).map((_, i) => {
          const a = (i * 137.5) * Math.PI / 180;
          const r = 40 + Math.sqrt(i) * 22;
          const cx = 750 + Math.cos(a) * r;
          const cy = 700 + Math.sin(a) * r * 0.9;
          return <circle key={i} cx={cx} cy={cy} r={0.9 + (i % 3) * 0.4} fill="url(#dotFade)" />;
        })}
      </svg>
    </div>
  );
}

window.BackgroundFX = BackgroundFX;
