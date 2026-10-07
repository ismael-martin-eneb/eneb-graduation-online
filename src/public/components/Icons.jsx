// Iconos SVG inline fieles al Figma
const IconDiploma = ({ size = 24, color = "currentColor" }) => (
  <svg width={size} height={size} viewBox="0 0 40 40" fill="none" aria-hidden="true">
    <path d="M 0 20 L 0 12 C 0 6.343 0 3.515 1.757 1.757 C 3.515 0 6.343 0 12 0 L 28 0 C 33.657 0 36.485 0 38.243 1.757 C 40 3.515 40 6.343 40 12 L 40 20 C 40 25.657 40 28.485 38.243 30.243 C 37.29 31.195 36.023 31.632 34.16 31.831 C 34.012 31.631 33.872 31.474 33.772 31.364 C 33.54 31.109 33.246 30.818 32.96 30.537 L 29.974 27.592 L 28.871 26.475 C 28.147 22.23 24.451 19 20 19 C 15.549 19 11.854 22.23 11.129 26.475 L 10.026 27.592 L 7.04 30.537 C 6.755 30.818 6.46 31.109 6.228 31.364 C 6.128 31.474 5.988 31.631 5.84 31.831 C 3.978 31.632 2.71 31.195 1.757 30.243 C 0 28.485 0 25.657 0 20 Z M 14 6.5 C 13.172 6.5 12.5 7.172 12.5 8 C 12.5 8.828 13.172 9.5 14 9.5 L 26 9.5 C 26.828 9.5 27.5 8.828 27.5 8 C 27.5 7.172 26.828 6.5 26 6.5 L 14 6.5 Z M 8.5 15 C 8.5 14.172 9.172 13.5 10 13.5 L 30 13.5 C 30.828 13.5 31.5 14.172 31.5 15 C 31.5 15.828 30.828 16.5 30 16.5 L 10 16.5 C 9.172 16.5 8.5 15.828 8.5 15 Z" fill={color} fillRule="evenodd"/>
    <path d="M 26 28 C 26 31.314 23.314 34 20 34 C 16.686 34 14 31.314 14 28 C 14 24.686 16.686 22 20 22 C 23.314 22 26 24.686 26 28 Z" fill={color}/>
    <path d="M 11.351 30.499 L 9.19 32.63 C 8.542 33.269 8.218 33.588 8.106 33.859 C 7.851 34.476 8.069 35.16 8.626 35.484 C 8.87 35.626 9.31 35.671 10.191 35.76 C 10.688 35.81 10.937 35.835 11.145 35.911 C 11.612 36.081 11.974 36.439 12.147 36.898 C 12.224 37.104 12.249 37.349 12.3 37.839 C 12.39 38.708 12.435 39.142 12.58 39.383 C 12.909 39.932 13.602 40.147 14.228 39.895 C 14.502 39.785 14.826 39.465 15.474 38.826 L 17.635 36.686 C 14.61 35.864 12.218 33.505 11.351 30.499 Z" fill={color}/>
    <path d="M 22.365 36.686 L 24.526 38.826 C 25.174 39.465 25.498 39.785 25.772 39.895 C 26.398 40.147 27.091 39.932 27.42 39.383 C 27.565 39.142 27.61 38.708 27.7 37.839 C 27.751 37.349 27.776 37.104 27.853 36.898 C 28.026 36.439 28.388 36.081 28.855 35.911 C 29.063 35.835 29.312 35.81 29.809 35.76 C 30.69 35.671 31.13 35.626 31.374 35.484 C 31.931 35.16 32.149 34.476 31.894 33.859 C 31.782 33.588 31.458 33.269 30.81 32.63 L 28.649 30.499 C 27.782 33.505 25.39 35.864 22.365 36.686 Z" fill={color}/>
  </svg>
);

const IconChart = ({ size = 24, color = "currentColor" }) => (
  <svg width={size} height={size * (40/43)} viewBox="0 0 43 40" fill="none" aria-hidden="true">
    <path d="M 38 23.5 C 38 22.672 37.328 22 36.5 22 L 30.5 22 C 29.672 22 29 22.672 29 23.5 L 29 37 L 26 37 L 26 4.5 C 26 3.043 25.997 2.102 25.904 1.408 C 25.816 0.757 25.675 0.554 25.561 0.439 C 25.446 0.325 25.243 0.184 24.592 0.096 C 23.898 0.003 22.957 0 21.5 0 C 20.043 0 19.102 0.003 18.408 0.096 C 17.757 0.184 17.554 0.325 17.439 0.439 C 17.325 0.554 17.184 0.757 17.096 1.408 C 17.003 2.102 17 3.043 17 4.5 L 17 37 L 14 37 L 14 13.5 C 14 12.672 13.328 12 12.5 12 L 6.5 12 C 5.672 12 5 12.672 5 13.5 L 5 37 L 2 37 L 1.5 37 C 0.672 37 0 37.672 0 38.5 C 0 39.328 0.672 40 1.5 40 L 41.5 40 C 42.328 40 43 39.328 43 38.5 C 43 37.672 42.328 37 41.5 37 L 41 37 L 38 37 L 38 23.5 Z" fill={color}/>
  </svg>
);

const IconMedal = ({ size = 24, color = "currentColor" }) => (
  <svg width={size} height={size} viewBox="0 0 40 40" fill="none" aria-hidden="true">
    <circle cx="20" cy="22" r="10" stroke={color} strokeWidth="2.2" fill="none"/>
    <path d="M 12 5 L 16 5 L 20 12 L 24 5 L 28 5 L 23 14 L 17 14 Z" fill={color}/>
    <circle cx="20" cy="22" r="5" fill={color}/>
  </svg>
);

const IconFlame = ({ size = 24, color = "currentColor" }) => (
  <svg width={size} height={size * (38.246/32)} viewBox="0 0 32 38.246" fill="none" aria-hidden="true">
    <path d="M 32 24.7 C 32 33.209 26.764 36.942 22.718 38.202 C 21.855 38.47 21.288 37.464 21.804 36.722 C 23.565 34.192 25.6 30.331 25.6 26.7 C 25.6 22.798 22.312 18.193 19.744 15.352 C 19.157 14.702 18.133 15.132 18.101 16.007 C 17.995 18.905 17.538 22.783 15.566 25.822 C 15.248 26.312 14.574 26.352 14.213 25.895 C 13.596 25.115 12.98 24.153 12.364 23.392 C 12.032 22.982 11.432 22.977 11.049 23.339 C 9.557 24.753 7.467 26.957 7.467 29.7 C 7.467 31.559 8.187 33.509 9 35.078 C 9.447 35.941 8.652 36.98 7.791 36.528 C 4.226 34.657 0 30.867 0 24.7 C 0 18.407 8.621 9.689 11.912 1.453 C 12.431 0.154 14.032 -0.457 15.145 0.391 C 21.888 5.527 32 15.456 32 24.7 Z" fill={color}/>
  </svg>
);

const IconSearch = ({ size = 20, color = "currentColor" }) => (
  <svg width={size} height={size} viewBox="0 0 24 24" fill="none" aria-hidden="true">
    <circle cx="11" cy="11" r="7.5" stroke={color} strokeWidth="1.8"/>
    <path d="M 17 17 L 21 21" stroke={color} strokeWidth="1.8" strokeLinecap="round"/>
  </svg>
);

const IconList = ({ size = 24, color = "currentColor" }) => (
  <svg width={size} height={size} viewBox="0 0 24 24" fill="none" aria-hidden="true">
    <path d="M 3 6 L 21 6 M 3 12 L 21 12 M 3 18 L 15 18" stroke={color} strokeWidth="2" strokeLinecap="round"/>
  </svg>
);

const IconClose = ({ size = 24, color = "currentColor" }) => (
  <svg width={size} height={size} viewBox="0 0 24 24" fill="none" aria-hidden="true">
    <path d="M 5 5 L 19 19 M 19 5 L 5 19" stroke={color} strokeWidth="2" strokeLinecap="round"/>
  </svg>
);

const IconShare = ({ size = 20, color = "currentColor" }) => (
  <svg width={size} height={size} viewBox="0 0 24 24" fill="none" aria-hidden="true">
    <circle cx="6" cy="12" r="2.5" stroke={color} strokeWidth="1.8"/>
    <circle cx="18" cy="6" r="2.5" stroke={color} strokeWidth="1.8"/>
    <circle cx="18" cy="18" r="2.5" stroke={color} strokeWidth="1.8"/>
    <path d="M 8 11 L 16 7 M 8 13 L 16 17" stroke={color} strokeWidth="1.8" strokeLinecap="round"/>
  </svg>
);

const IconGlobe = ({ size = 18, color = "currentColor" }) => (
  <svg width={size} height={size} viewBox="0 0 24 24" fill="none" aria-hidden="true">
    <circle cx="12" cy="12" r="9" stroke={color} strokeWidth="1.6"/>
    <ellipse cx="12" cy="12" rx="4" ry="9" stroke={color} strokeWidth="1.6"/>
    <path d="M 3 12 L 21 12" stroke={color} strokeWidth="1.6"/>
  </svg>
);

const IconGrade = ({ size = 18, color = "currentColor" }) => (
  <svg width={size} height={size} viewBox="0 0 24 24" fill="none" aria-hidden="true">
    <path d="M 12 2 L 14.5 8.5 L 21.5 9 L 16 13.5 L 17.8 20.5 L 12 16.5 L 6.2 20.5 L 8 13.5 L 2.5 9 L 9.5 8.5 Z" fill={color}/>
  </svg>
);

Object.assign(window, {
  IconDiploma, IconChart, IconMedal, IconFlame,
  IconSearch, IconList, IconClose, IconShare, IconGlobe, IconGrade,
});
