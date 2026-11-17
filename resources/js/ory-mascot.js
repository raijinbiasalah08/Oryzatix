import * as T from 'three';
import { RoundedBoxGeometry } from 'three/addons/geometries/RoundedBoxGeometry.js';

function buildOry() {
    const root = new T.Group();
    const palette = {};
    for (const [key, color] of Object.entries({
        armor: 0xf4faf7, rim: 0x48869c, joint: 0x183d50, visor: 0x062d38,
        cyan: 0x39fff0, leaf: 0x61ac39, gold: 0xefbd37, screen: 0x117e80,
    })) {
        palette[key] = new T.MeshStandardMaterial({
            color, metalness: key === 'rim' ? 0.58 : 0.16,
            roughness: key === 'visor' ? 0.16 : 0.36,
            emissive: key === 'cyan' ? color : 0, emissiveIntensity: 0.65,
        });
    }
    const sphere = new T.SphereGeometry(1, 32, 20);
    function mesh(geometry, material, position, parent = root) {
        const part = new T.Mesh(geometry, palette[material]);
        part.position.set(...position);
        parent.add(part);
        return part;
    }
    function oval(material, position, scale, parent) {
        const part = mesh(sphere, material, position, parent);
        part.scale.set(...scale);
        return part;
    }
    function panel(material, position, size, radius, parent) {
        return mesh(new RoundedBoxGeometry(...size, 3, radius), material, position, parent);
    }
    function stroke(material, points, radius, parent) {
        const curve = new T.CatmullRomCurve3(points.map(p => new T.Vector3(...p)));
        return mesh(new T.TubeGeometry(curve, 16, radius, 8, false), material, [0,0,0], parent);
    }

    oval('joint', [0,-0.65,0], [0.49,0.6,0.4]);
    oval('armor', [0,-0.58,0.06], [0.55,0.51,0.42]);
    panel('rim', [0,-0.59,0.42], [0.56,0.37,0.09], 0.1);
    panel('visor', [0,-0.59,0.47], [0.47,0.3,0.06], 0.08);
    for (const side of [-1,1]) {
        oval('gold', [side*0.07,-0.57,0.51], [0.048,0.1,0.024]).rotation.z = -side*0.65;
        oval('rim', [side*0.49,-0.36,0.03], [0.23,0.22,0.22]);
        oval('armor', [side*0.58,-0.49,0.17], [0.18,0.25,0.19]).rotation.z = side*0.5;
        oval('joint', [side*0.66,-0.55,0.34], [0.16,0.15,0.14]);
        oval('armor', [side*0.7,-0.5,0.45], [0.165,0.18,0.15]);
        for (let i=0; i<3; i++) oval('armor',[side*0.71,-0.42-i*0.084,0.56],[0.13,0.053,0.09]);
    }
    oval('armor',[0,-1.03,0.05],[0.27,0.23,0.27]);
    oval('cyan',[0,-1.18,0.06],[0.14,0.055,0.15]);
    panel('armor',[0,0.21,0],[1.45,0.87,0.86],0.33);
    panel('rim',[0,0.21,0.29],[1.37,0.78,0.42],0.2);
    panel('visor',[0,0.21,0.41],[1.22,0.65,0.29],0.14);
    for (const s of [-1,1]) {
        const x=s*0.29;
        stroke('cyan',[[x-0.12,0.24,0.56],[x,0.34,0.57],[x+0.12,0.24,0.56]],0.034);
        oval('rim',[s*0.72,0.19,0],[0.12,0.24,0.23]);
        oval('cyan',[s*0.81,0.2,0],[0.045,0.13,0.13]);
        stroke('joint',[[s*0.47,0.58,-0.14],[s*0.79,0.79,-0.17],[s*1.02,0.82,-0.18]],0.055);
        const guard=mesh(new T.TorusGeometry(0.3,0.038,10,48),'rim',[s*1.03,0.89,-0.18]);
        guard.rotation.x=Math.PI/2-0.15;
        oval('cyan',[s*1.03,0.89,-0.18],[0.26,0.019,0.055]).rotation.y=s*0.5;
        oval('armor',[s*1.03,0.89,-0.18],[0.056,0.052,0.056]);
    }
    stroke('cyan',[[-0.1,0.09,0.56],[0,0.045,0.57],[0.1,0.09,0.56]],0.018);
    oval('rim',[0,0.58,0],[0.89,0.085,0.59]);
    oval('armor',[0,0.62,0],[0.87,0.08,0.58]);
    oval('armor',[0,0.62,-0.02],[0.67,0.48,0.49]);
    function rice(x,y,z,scale) {
        const stalk=new T.Group();
        root.add(stalk); stalk.position.set(x,y,z); stalk.scale.setScalar(scale);
        stroke('gold',[[0,0,0],[0.1,0.23,0],[0.29,0.38,0],[0.48,0.39,0]],0.018,stalk);
        for(let i=0;i<5;i++) {
            const t=i/4;
            oval('gold',[0.1+t*0.36,0.24+0.12*Math.sin(t*2),0.015],[0.037,0.085,0.026],stalk).rotation.z=-0.6-t*0.5;
        }
        oval('leaf',[-0.015,0.12,0],[0.038,0.14,0.029],stalk).rotation.z=0.4;
    }
    rice(-0.12,0.66,0.47,0.66); rice(0.48,0.7,-0.03,1.36); rice(0.56,0.74,0,0.91);
    const tablet=new T.Group(); root.add(tablet);
    tablet.position.set(0.86,-0.24,0.51); tablet.rotation.set(-0.12,-0.24,-0.12);
    panel('rim',[0,0,0],[0.61,0.66,0.09],0.065,tablet);
    panel('screen',[0,0,0.05],[0.53,0.57,0.035],0.045,tablet);
    for(let i=0;i<4;i++) panel('cyan',[-0.15+i*0.1,-0.17+i*0.024,0.075],[0.053,0.09+i*0.048,0.015],0.006,tablet);
    oval('leaf',[-0.075,0.11,0.08],[0.055,0.108,0.014],tablet).rotation.z=-0.7;
    return root;
}

const button = document.querySelector('.oryzatix-assistant-shortcut');
const image = button?.querySelector('img');
function mount() {
    const canvas = document.createElement('canvas');
    const renderer = new T.WebGLRenderer({canvas, alpha:true, antialias:true});
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    renderer.setSize(192,156,false);
    const scene = new T.Scene();
    const model = buildOry(); scene.add(model);
    const camera = new T.OrthographicCamera(-1.62,1.62,1.32,-1.32,0.1,20);
    camera.position.set(0.12,0.74,6); camera.lookAt(0,0,0);
    scene.add(new T.HemisphereLight(0xf4ffff,0x96b9bc,2.1));
    const key = new T.DirectionalLight(0xfff5d7,2.6);
    key.position.set(-3,5,4); scene.add(key);
    function draw(turn) { model.rotation.y=turn; renderer.render(scene,camera); }
    draw(-0.08);
    canvas.className=image.className; canvas.setAttribute('aria-hidden','true');
    canvas.style.pointerEvents='none';
    image.after(canvas); image.style.display='none';
    button.addEventListener('pointerenter',()=>draw(0.13));
    button.addEventListener('pointerleave',()=>draw(-0.08));
    canvas.addEventListener('webglcontextlost',()=>{ canvas.hidden=true; canvas.style.display='none'; image.style.display=''; });
}
if (image) {
    const observer=new IntersectionObserver(entries=>{
        if (!entries.some(entry=>entry.isIntersecting)) return;
        observer.disconnect();
        try { mount(); } catch (error) { console.warn('Ory 3D unavailable', error); }
    });
    observer.observe(image);
}
