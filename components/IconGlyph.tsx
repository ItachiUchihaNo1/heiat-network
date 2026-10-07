const map: Record<string,string> = {
  home:'⌂', knowledge:'▤', book:'▤', plus:'＋', issue:'＋', sessions:'◫', calendar:'◫',
  profile:'○', user:'○', users:'◎', experts:'◎', search:'⌕', menu:'☰', network:'⌘',
  media:'▣', settings:'⚙', dashboard:'◈', category:'◇', edit:'✎', bell:'●', star:'★',
  heart:'♡', arrow:'←', money:'◈', building:'⌂', child:'♧', service:'✦', content:'▤',
};

export function IconGlyph({icon, className=''}:{icon?:string|null;className?:string}){
  const value = icon ? (map[icon.toLowerCase()] || icon) : '•';
  return <span className={className} aria-hidden>{value}</span>;
}
