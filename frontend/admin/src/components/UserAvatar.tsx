import { Avatar } from '@arco-design/web-react';
import { useState, type CSSProperties } from 'react';

const avatarColors = [
  '#168CFF', '#7BC616', '#14C9C9', '#FF7D00', '#FFC72E',
];

/** 与参考组件相同，按姓名的字符编码稳定选取五色之一。 */
function colorForName(name: string): string {
  let hash = 0;
  for (let index = 0; index < name.length; index += 1) {
    hash = (hash << 5) - hash + name.charCodeAt(index);
    hash |= 0;
  }
  return avatarColors[Math.abs(hash) % avatarColors.length];
}

/** 英文取前两字母或姓名首字母，较长中文取首字，其余取末两字。 */
function abbreviation(name: string): string {
  if (!name) return '?';
  const characters = Array.from(name);
  if (/^[A-Za-z]$/.test(characters[0])) {
    const words = name.split(/\s+/);
    if (words.length > 1 && /^[A-Za-z]/.test(words[1])) {
      return `${words[0][0]}${words[1][0]}`;
    }
    return characters.slice(0, 2).join('');
  }
  return characters.length > 4 ? characters[0] : characters.slice(-2).join('');
}

/** 用户头像：优先显示图片，图片缺失或加载失败时显示昵称缩写。 */
export interface UserAvatarProps {
  name?: string | null;
  text?: string | null;
  src?: string | null;
  alt?: string;
  size?: number;
  className?: string;
  style?: CSSProperties;
}

/**
 * 展示用户头像，图片不可用时使用昵称缩写和稳定背景色。
 * @param props 用户昵称、可选图片地址及 Arco 头像外观属性
 * @returns 头像组件
 */
export default function UserAvatar({ name, text, src, alt = '头像', size = 20, className, style }: UserAvatarProps) {
  const [failedSource, setFailedSource] = useState<string | null>(null);
  const displayName = name?.trim() || '';
  const fallbackText = text?.trim() || '';
  const colorName = displayName || fallbackText;
  const imageSource = src?.trim() || '';
  const showImage = imageSource !== '' && imageSource !== failedSource;
  const initials = displayName ? abbreviation(displayName) : fallbackText || '?';

  return (
    <Avatar
      size={size}
      className={className}
      style={showImage ? style : { backgroundColor: colorForName(colorName), ...style }}
      aria-label={displayName ? `${displayName}的头像` : alt}
    >
      {showImage
        ? <img src={imageSource} alt="" onError={() => setFailedSource(imageSource)} />
        : initials}
    </Avatar>
  );
}
