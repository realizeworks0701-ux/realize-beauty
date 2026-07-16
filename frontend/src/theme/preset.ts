import { definePreset } from '@primeuix/themes'
import Aura from '@primeuix/themes/aura'

/**
 * Realize Beauty theme preset.
 * 白×くすみピンク×ベージュを基調とした女性向けテーマ。
 * docs/ui/design-system.md 参照。
 */
export const RealizePreset = definePreset(Aura, {
  primitive: {
    borderRadius: {
      none: '0',
      xs: '6px',
      sm: '10px',
      md: '12px',
      lg: '16px',
      xl: '20px',
    },
  },
  semantic: {
    primary: {
      50: '#FDF2F5',
      100: '#FBE4EB',
      200: '#F6C9D6',
      300: '#EFA8BC',
      400: '#E48AA3',
      500: '#D86C8A',
      600: '#C25373',
      700: '#A2415D',
      800: '#83344B',
      900: '#6B2B3E',
      950: '#3F1622',
    },
    colorScheme: {
      light: {
        surface: {
          0: '#FFFFFF',
          50: '#FDF9F6',
          100: '#F8F1EC',
          200: '#F0E4E8',
          300: '#E3D3CB',
          400: '#C9B2A8',
          500: '#9A8D91',
          600: '#7C6F73',
          700: '#5F5457',
          800: '#4B4247',
          900: '#3A3236',
          950: '#2A2427',
        },
        text: {
          color: '#4B4247',
          hoverColor: '#3A3236',
          mutedColor: '#9A8D91',
          hoverMutedColor: '#7C6F73',
        },
        content: {
          background: 'rgba(255, 255, 255, 0.72)',
          hoverBackground: '#FBE4EB',
          borderColor: '#F0E4E8',
          color: '#4B4247',
          hoverColor: '#3A3236',
        },
        highlight: {
          background: '#FBE4EB',
          focusBackground: '#F6C9D6',
          color: '#A2415D',
          focusColor: '#83344B',
        },
      },
    },
  },
  components: {
    // カレンダーのオーバーレイは不透明にする（content.background は半透明のため、
    // 背後の予約枠が透けて見えてしまう）
    datepicker: {
      panel: {
        background: '{surface.0}',
      },
    },
  },
})
