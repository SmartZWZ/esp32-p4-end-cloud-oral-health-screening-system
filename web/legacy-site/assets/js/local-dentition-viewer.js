import * as THREE from '../vendor/three/three.module.js';
import { GLTFLoader } from '../vendor/three/addons/loaders/GLTFLoader.js';
import { OrbitControls } from '../vendor/three/addons/controls/OrbitControls.js';

const MODEL_URL = 'assets/models/permanent-dentition/scene.gltf';

const NAME_TO_FDI = new Map([
  ['LL1seam_2:ZBrushPolyMesh3D_Mandible_ll1_0', 31],
  ['LL2seam_2:ZBrushPolyMesh3D_Mandible_l2_0', 32],
  ['LL3seam_2:ZBrushPolyMesh3D_Mandible_ll3_0', 33],
  ['LL4seam_2:ZBrushPolyMesh3D_Mandible_ll4_0', 34],
  ['LL5seam_3:ZBrushPolyMesh3D_Mandible_ll5_0', 35],
  ['LL6seam_2:ZBrushPolyMesh3D_Mandible_blinn10_0', 36],
  ['LL7seam_2:ZBrushPolyMesh3D_Mandible_ll7_0', 37],
  ['LL8seam_2:ZBrushPolyMesh3D_Mandible_ll8_0', 38],
  ['ZBrushPolyMesh3D_Mandible_ll8_0', 48],
  ['ZBrushPolyMesh3D1_Mandible_ll7_0', 47],
  ['ZBrushPolyMesh3D2_Mandible_blinn10_0', 46],
  ['ZBrushPolyMesh3D3_Mandible_ll4_0', 44],
  ['ZBrushPolyMesh3D4_Mandible_ll5_0', 45],
  ['ZBrushPolyMesh3D5_Mandible_ll3_0', 43],
  ['ZBrushPolyMesh3D6_Mandible_l2_0', 42],
  ['ZBrushPolyMesh3D7_Mandible_ll1_0', 41],
  ['polySurface2_UL1_0', 11],
  ['polySurface4_blinn14_0', 12],
  ['polySurface6_blinn15_0', 13],
  ['ZBrushPolyMesh3D8_blinn16_0', 14],
  ['ZBrushPolyMesh3D9_blinn17_0', 15],
  ['polySurface7_blinn18_0', 16],
  ['polySurface8_blinn18_0', 16],
  ['polySurface10_blinn19_0', 17],
  ['ZBrushPolyMesh3D7_blinn20_0', 18],
  ['polySurface1_UL1_0', 21],
  ['ZBrushPolyMesh3D1_blinn14_0', 22],
  ['ZBrushPolyMesh3D2_blinn15_0', 23],
  ['ZBrushPolyMesh3D3_blinn16_0', 24],
  ['ZBrushPolyMesh3D4_blinn17_0', 25],
  ['ZBrushPolyMesh3D5_blinn18_0', 26],
  ['polySurface9_blinn19_0', 27],
  ['polySurface12_blinn20_0', 28],
]);
const normalizeNodeName = (name) => String(name || '').replace(/[^A-Za-z0-9_]/g, '');
const NORMALIZED_NAME_TO_FDI = new Map([...NAME_TO_FDI].map(([name, tooth]) => [normalizeNodeName(name), tooth]));

function materialsOf(mesh) {
  return Array.isArray(mesh.material) ? mesh.material : [mesh.material];
}

function rememberMaterial(material) {
  if (!material?.userData.chijingOriginal) {
    material.userData.chijingOriginal = {
      emissive: material.emissive?.getHex?.() ?? null,
      emissiveIntensity: material.emissiveIntensity,
      opacity: material.opacity,
      transparent: material.transparent,
      depthWrite: material.depthWrite,
    };
  }
  return material.userData.chijingOriginal;
}

function setMeshVisual(mesh, state, recordStatus = 'unrecorded') {
  materialsOf(mesh).forEach((material) => {
    if (!material) return;
    const original = rememberMaterial(material);
    const active = state === 'active';
    const dimmed = state === 'dimmed';
    const statusColor = { trusted: 0x247fd1, review: 0xe68a22, abnormal: 0xd54444 }[recordStatus];
    if (material.emissive) material.emissive.setHex(active ? 0x29b9dc : (statusColor ?? original.emissive ?? 0x000000));
    if ('emissiveIntensity' in material) material.emissiveIntensity = active ? 1.15 : (statusColor ? .48 : original.emissiveIntensity);
    material.transparent = dimmed || original.transparent;
    material.opacity = dimmed ? 0.2 : original.opacity;
    material.depthWrite = dimmed ? false : original.depthWrite;
    material.needsUpdate = true;
  });

  let outline = mesh.userData.chijingOutline;
  if (state === 'active' && !outline) {
    outline = new THREE.Mesh(mesh.geometry, new THREE.MeshBasicMaterial({
      color: 0x6fe5ff,
      side: THREE.BackSide,
      transparent: true,
      opacity: 0.88,
      depthWrite: false,
    }));
    outline.scale.setScalar(1.028);
    outline.raycast = () => null;
    outline.renderOrder = 8;
    mesh.add(outline);
    mesh.userData.chijingOutline = outline;
  }
  if (outline) outline.visible = state === 'active';
}

export function createLocalDentitionViewer(container, callbacks = {}) {
  const scene = new THREE.Scene();
  const camera = new THREE.PerspectiveCamera(32, 1, 0.01, 100);
  const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true, preserveDrawingBuffer: true });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  renderer.toneMapping = THREE.ACESFilmicToneMapping;
  renderer.toneMappingExposure = 1.05;
  renderer.shadowMap.enabled = false;
  container.replaceChildren(renderer.domElement);

  const controls = new OrbitControls(camera, renderer.domElement);
  controls.enableDamping = true;
  controls.dampingFactor = 0.075;
  controls.enablePan = false;
  controls.minDistance = 3.2;
  controls.maxDistance = 12;
  controls.rotateSpeed = 0.72;
  controls.zoomSpeed = 0.85;

  scene.add(new THREE.HemisphereLight(0xf7fbff, 0x637078, 2.35));
  const key = new THREE.DirectionalLight(0xffffff, 3.7); key.position.set(4, 6, 7); scene.add(key);
  const fill = new THREE.DirectionalLight(0xbfd9e5, 2.0); fill.position.set(-5, 1, 3); scene.add(fill);
  const rim = new THREE.DirectionalLight(0xffeee3, 1.5); rim.position.set(0, -5, -4); scene.add(rim);

  const toothMeshes = [];
  const meshesByTooth = new Map();
  const raycaster = new THREE.Raycaster();
  const pointer = new THREE.Vector2();
  let model = null;
  let hoveredTooth = null;
  let pointerStart = null;
  let disposed = false;
  const toothStatuses = new Map();

  function fitCamera() {
    if (!model) return;
    const box = new THREE.Box3().setFromObject(model);
    const center = box.getCenter(new THREE.Vector3());
    const size = box.getSize(new THREE.Vector3());
    const radius = Math.max(size.x, size.y, size.z) * 0.56;
    controls.target.copy(center);
    camera.position.copy(center).add(new THREE.Vector3(0, radius * 0.15, radius * 3.2));
    camera.near = Math.max(0.01, radius / 100);
    camera.far = radius * 30;
    camera.updateProjectionMatrix();
    controls.minDistance = radius * 1.65;
    controls.maxDistance = radius * 6.5;
    controls.update();
  }

  function resize() {
    const width = Math.max(1, container.clientWidth);
    const height = Math.max(1, container.clientHeight);
    renderer.setSize(width, height, false);
    camera.aspect = width / height;
    camera.updateProjectionMatrix();
  }

  function toothAt(event) {
    const rect = renderer.domElement.getBoundingClientRect();
    pointer.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
    pointer.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;
    raycaster.setFromCamera(pointer, camera);
    const hit = raycaster.intersectObjects(toothMeshes, false)[0];
    return hit?.object?.userData?.fdi || null;
  }

  function highlightTooth(tooth) {
    if (hoveredTooth === tooth) return;
    hoveredTooth = tooth;
    meshesByTooth.forEach((meshes, number) => {
      const visual = !tooth ? 'normal' : (number === tooth ? 'active' : 'dimmed');
      meshes.forEach((mesh) => setMeshVisual(mesh, visual, toothStatuses.get(number) || 'unrecorded'));
    });
    renderer.domElement.style.cursor = tooth ? 'pointer' : 'grab';
    callbacks.onHover?.(tooth);
  }

  renderer.domElement.addEventListener('pointerdown', (event) => {
    pointerStart = { x: event.clientX, y: event.clientY };
    container.classList.add('is-dragging');
  });
  renderer.domElement.addEventListener('pointermove', (event) => {
    if (pointerStart && Math.hypot(event.clientX - pointerStart.x, event.clientY - pointerStart.y) > 5) return;
    highlightTooth(toothAt(event));
    const rect = renderer.domElement.getBoundingClientRect();
    callbacks.onPointer?.({ tooth: hoveredTooth, x: event.clientX - rect.left, y: event.clientY - rect.top });
  });
  renderer.domElement.addEventListener('pointerup', (event) => {
    container.classList.remove('is-dragging');
    const moved = pointerStart && Math.hypot(event.clientX - pointerStart.x, event.clientY - pointerStart.y) > 5;
    pointerStart = null;
    if (moved) return;
    const tooth = toothAt(event);
    if (tooth) callbacks.onSelect?.(tooth);
  });
  renderer.domElement.addEventListener('pointerleave', () => {
    pointerStart = null;
    container.classList.remove('is-dragging');
    highlightTooth(null);
    callbacks.onPointer?.({ tooth: null, x: 0, y: 0 });
  });

  const resizeObserver = new ResizeObserver(resize);
  resizeObserver.observe(container);
  resize();

  function animate() {
    if (disposed) return;
    controls.update();
    renderer.render(scene, camera);
    requestAnimationFrame(animate);
  }
  animate();

  new GLTFLoader().load(
    MODEL_URL,
    (gltf) => {
      model = gltf.scene;
      model.traverse((object) => {
        if (!object.isMesh) return;
        // Three.js 会清理节点名中的冒号；同时对原始名和清理后的名称建立映射。
        const tooth = NAME_TO_FDI.get(object.name) || NORMALIZED_NAME_TO_FDI.get(normalizeNodeName(object.name));
        if (tooth) {
          object.userData.fdi = tooth;
          toothMeshes.push(object);
          if (!meshesByTooth.has(tooth)) meshesByTooth.set(tooth, []);
          meshesByTooth.get(tooth).push(object);
        }
        object.material = Array.isArray(object.material)
          ? object.material.map((material) => material.clone())
          : object.material.clone();
      });
      scene.add(model);
      meshesByTooth.forEach((meshes, number) => meshes.forEach((mesh) => setMeshVisual(mesh, 'normal', toothStatuses.get(number) || 'unrecorded')));
      fitCamera();
      callbacks.onReady?.({ mappedTeeth: meshesByTooth.size, meshCount: toothMeshes.length });
    },
    (event) => {
      if (event.total > 0) callbacks.onProgress?.(Math.round(event.loaded / event.total * 100));
    },
    (error) => callbacks.onError?.(error),
  );

  return {
    setStatuses(statuses = {}) {
      toothStatuses.clear();
      Object.entries(statuses).forEach(([tooth, status]) => toothStatuses.set(Number(tooth), status));
      meshesByTooth.forEach((meshes, number) => meshes.forEach((mesh) => setMeshVisual(mesh, number === hoveredTooth ? 'active' : 'normal', toothStatuses.get(number) || 'unrecorded')));
    },
    setArch(arch = 'both') {
      meshesByTooth.forEach((meshes, tooth) => {
        const upper = tooth < 30;
        const visible = arch === 'both' || (arch === 'upper' && upper) || (arch === 'lower' && !upper);
        meshes.forEach((mesh) => { mesh.visible = visible; });
      });
    },
    reset: fitCamera,
    download() {
      renderer.render(scene, camera);
      const link = document.createElement('a');
      link.download = `chijing-local-dentition-${Date.now()}.png`;
      link.href = renderer.domElement.toDataURL('image/png');
      link.click();
    },
    dispose() {
      disposed = true;
      resizeObserver.disconnect();
      controls.dispose();
      renderer.dispose();
    },
  };
}
