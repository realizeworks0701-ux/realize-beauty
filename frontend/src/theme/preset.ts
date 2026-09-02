import { definePreset } from '@primeuix/themes'
import Aura from '@primeuix/themes/aura'

/**
 * Realize Beauty theme preset.
 * ラベンダー/パープルを基調とした管理画面テーマ。
 * 色の値は main.css の --rb-* トークンと対応させること（二重管理のため）。
 * docs/ui/design-system.md / ADR-027 参照。
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
      50: '#F5F1FD',
      100: '#ECE5FA',
      200: '#C9B8EC',
      300: '#AC95E0',
      400: '#9478D2',
      500: '#7C5CBF',
      600: '#6D4FA8',
      700: '#59408C',
      800: '#473370',
      900: '#382959',
      950: '#221935',
    },
    colorScheme: {
      light: {
        surface: {
          0: '#FFFFFF',
          50: '#F7F6FB',
          100: '#F1EFF8',
          200: '#EEEBF5',
          300: '#D9D4E6',
          400: '#B4AEC6',
          500: '#6F6A7D',
          600: '#5C5768',
          700: '#494553',
          800: '#2E2A38',
          900: '#241F2C',
          950: '#17131D',
        },
        text: {
          color: '#2E2A38',
          hoverColor: '#17131D',
          mutedColor: '#6F6A7D',
          hoverMutedColor: '#5C5768',
        },
        content: {
          background: '#FFFFFF',
          hoverBackground: '#F5F1FD',
          borderColor: '#EEEBF5',
          color: '#2E2A38',
          hoverColor: '#17131D',
        },
        highlight: {
          background: '#ECE5FA',
          focusBackground: '#C9B8EC',
          color: '#59408C',
          focusColor: '#473370',
        },
      },
    },
  },
  components: {
    // Drawer のパネルは body へ Teleport されるため scoped CSS が届かない。
    // サイドバーと同じグラデーションをここで指定する。
    drawer: {
      root: {
        background: 'linear-gradient(160deg, #9b7bd6 0%, #7c5cbf 55%, #6d4fa8 100%)',
        borderColor: 'transparent',
        color: '#ffffff',
      },
    },
  },
})
