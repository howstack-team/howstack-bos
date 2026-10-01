declare module '*.svg' {
  const content: React.FunctionComponent<React.SVGAttributes<SVGElement>>;
  export default content;
}

declare module '*.less' {
  const classes: { [className: string]: string };
  export default classes;
}

declare module '*/settings.json' {
  const value: {
    colorWeek: boolean;
    watermark: boolean;
    navbar: boolean;
    menu: boolean;
    footer: boolean;
    themeColor: string;
    menuWidth: number;
  };

  export default value;
}

declare module '*.png' {
  const value: string;
  export default value;
}
