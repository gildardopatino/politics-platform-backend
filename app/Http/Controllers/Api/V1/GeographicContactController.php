<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GeographicContact;
use App\Models\Department;
use App\Models\Municipality;
use App\Models\Commune;
use App\Models\Barrio;
use App\Models\Corregimiento;
use App\Models\Vereda;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class GeographicContactController extends Controller
{
    /**
     * Constructor - Aplicar middleware de permisos
     */
    public function __construct()
    {
        //$this->middleware('permission:gestion_enlaces');
    }

    /**
     * Mapeo de tipos de entidades geográficas
     */
    private const CONTACTABLE_TYPES = [
        'department' => Department::class,
        'municipality' => Municipality::class,
        'commune' => Commune::class,
        'barrio' => Barrio::class,
        'corregimiento' => Corregimiento::class,
        'vereda' => Vereda::class,
    ];

    /**
     * Listar enlaces de una entidad geográfica
     * 
     * GET /api/v1/geographic-contacts?type=municipality&id=1
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:' . implode(',', array_keys(self::CONTACTABLE_TYPES)),
            'id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $type = $request->input('type');
        $id = $request->input('id');
        $modelClass = self::CONTACTABLE_TYPES[$type];

        // Verificar que la entidad existe
        $entity = $modelClass::find($id);
        if (!$entity) {
            return response()->json([
                'success' => false,
                'message' => 'Entidad geográfica no encontrada',
            ], 404);
        }

        // Obtener enlaces
        $contacts = $entity->contacts()
            ->orderBy('apellidos')
            ->orderBy('nombres')
            ->get()
            ->map(function ($contact) use ($entity, $type) {
                return [
                    'id' => $contact->id,
                    'identificacion' => $contact->identificacion,
                    'nombres' => $contact->nombres,
                    'apellidos' => $contact->apellidos,
                    'nombre_completo' => $contact->nombre_completo,
                    'telefono' => $contact->telefono,
                    'direccion' => $contact->direccion,
                    'entity_type' => $type,
                    'entity_id' => $entity->id,
                    'entity_nombre' => $entity->nombre,
                    'created_at' => $contact->created_at,
                    'updated_at' => $contact->updated_at,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $contacts,
            'entity' => [
                'type' => $type,
                'id' => $entity->id,
                'nombre' => $entity->nombre,
            ],
        ]);
    }

    /**
     * Crear un nuevo enlace para una entidad geográfica
     * 
     * POST /api/v1/geographic-contacts
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:' . implode(',', array_keys(self::CONTACTABLE_TYPES)),
            'id' => 'required|integer',
            'identificacion' => 'required|string|max:20',
            'nombres' => 'required|string|max:255',
            'apellidos' => 'required|string|max:255',
            'telefono' => 'required|string|max:20',
            'direccion' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $type = $request->input('type');
        $id = $request->input('id');
        $modelClass = self::CONTACTABLE_TYPES[$type];

        // Verificar que la entidad existe
        $entity = $modelClass::find($id);
        if (!$entity) {
            return response()->json([
                'success' => false,
                'message' => 'Entidad geográfica no encontrada',
            ], 404);
        }

        // Crear el contacto
        $contact = new GeographicContact([
            'tenant_id' => auth()->user()->tenant_id,
            'identificacion' => $request->identificacion,
            'nombres' => $request->nombres,
            'apellidos' => $request->apellidos,
            'telefono' => $request->telefono,
            'direccion' => $request->direccion,
        ]);

        $entity->contacts()->save($contact);

        return response()->json([
            'success' => true,
            'message' => 'Enlace creado exitosamente',
            'data' => $contact,
        ], 201);
    }

    /**
     * Mostrar un enlace específico
     */
    public function show(GeographicContact $geographicContact): JsonResponse
    {
        $geographicContact->load('contactable');

        return response()->json([
            'success' => true,
            'data' => $geographicContact,
        ]);
    }

    /**
     * Actualizar un enlace
     */
    public function update(Request $request, GeographicContact $geographicContact): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'identificacion' => 'sometimes|string|max:20',
            'nombres' => 'sometimes|string|max:255',
            'apellidos' => 'sometimes|string|max:255',
            'telefono' => 'sometimes|string|max:20',
            'direccion' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $geographicContact->update($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Enlace actualizado exitosamente',
            'data' => $geographicContact,
        ]);
    }

    /**
     * Eliminar un enlace
     */
    public function destroy(GeographicContact $geographicContact): JsonResponse
    {
        $geographicContact->delete();

        return response()->json([
            'success' => true,
            'message' => 'Enlace eliminado exitosamente',
        ]);
    }

    /**
     * Listar todos los enlaces de todas las entidades (con filtros)
     * 
     * GET /api/v1/geographic-contacts/all
     */
    public function all(Request $request): JsonResponse
    {
        $query = GeographicContact::with('contactable');

        // Filtrar por tipo de entidad si se proporciona
        if ($request->has('type')) {
            $type = $request->input('type');
            if (isset(self::CONTACTABLE_TYPES[$type])) {
                $query->where('contactable_type', self::CONTACTABLE_TYPES[$type]);
            }
        }

        // Filtrar por búsqueda
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('identificacion', 'like', "%{$search}%")
                  ->orWhere('nombres', 'like', "%{$search}%")
                  ->orWhere('apellidos', 'like', "%{$search}%")
                  ->orWhere('telefono', 'like', "%{$search}%");
            });
        }

        $perPage = $request->input('per_page', 15);
        $contacts = $query->orderBy('apellidos')->orderBy('nombres')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $contacts->items(),
            'pagination' => [
                'total' => $contacts->total(),
                'per_page' => $contacts->perPage(),
                'current_page' => $contacts->currentPage(),
                'last_page' => $contacts->lastPage(),
            ],
        ]);
    }

    /**
     * Obtener árbol jerárquico de enlaces
     * Agrupa los enlaces según su jerarquía geográfica real
     * 
     * GET /api/v1/geographic-contacts/tree
     */
    public function tree(): JsonResponse
    {
        // Obtener todos los enlaces con sus entidades relacionadas
        $contacts = GeographicContact::with('contactable')->get();

        // Crear un mapa de entidades que tienen enlaces
        $entitiesWithContacts = [];
        
        foreach ($contacts as $contact) {
            $entity = $contact->contactable;
            if (!$entity) continue;

            $type = $this->getEntityType($entity);
            $key = $type . '_' . $entity->id;

            if (!isset($entitiesWithContacts[$key])) {
                $entitiesWithContacts[$key] = [
                    'entity_type' => $type,
                    'entity_id' => $entity->id,
                    'entity_nombre' => $entity->nombre,
                    'entity' => $entity,
                    'enlaces' => [],
                ];
            }

            $entitiesWithContacts[$key]['enlaces'][] = [
                'id' => $contact->id,
                'identificacion' => $contact->identificacion,
                'nombres' => $contact->nombres,
                'apellidos' => $contact->apellidos,
                'nombre_completo' => $contact->nombre_completo,
                'telefono' => $contact->telefono,
                'direccion' => $contact->direccion,
            ];
        }

        // Construir el árbol jerárquico
        $tree = [];
        $processed = [];

        foreach ($entitiesWithContacts as $key => $node) {
            if (in_array($key, $processed)) {
                continue;
            }

            $parent = $this->findParentWithContact($node['entity'], $entitiesWithContacts);

            if ($parent) {
                // Este nodo tiene un padre con enlace, será anidado después
                continue;
            }

            // Este nodo no tiene padre con enlace, es raíz
            $treeNode = $this->buildTreeNode($node, $entitiesWithContacts, $processed);
            $tree[] = $treeNode;
        }

        return response()->json([
            'success' => true,
            'data' => $tree,
        ]);
    }

    /**
     * Construir un nodo del árbol con sus hijos
     */
    private function buildTreeNode(array $node, array &$allEntities, array &$processed): array
    {
        $key = $node['entity_type'] . '_' . $node['entity_id'];
        $processed[] = $key;

        $treeNode = [
            'entity_type' => $node['entity_type'],
            'entity_id' => $node['entity_id'],
            'entity_nombre' => $node['entity_nombre'],
            'enlaces_count' => count($node['enlaces']),
            'enlaces' => $node['enlaces'],
            'children' => [],
        ];

        // Buscar hijos (entidades que tienen como padre esta entidad)
        foreach ($allEntities as $childKey => $childNode) {
            if (in_array($childKey, $processed)) {
                continue;
            }

            $parent = $this->findParentWithContact($childNode['entity'], $allEntities);
            
            if ($parent && $parent['entity_type'] === $node['entity_type'] && $parent['entity_id'] === $node['entity_id']) {
                // Este es un hijo directo
                $childTreeNode = $this->buildTreeNode($childNode, $allEntities, $processed);
                $treeNode['children'][] = $childTreeNode;
            }
        }

        return $treeNode;
    }

    /**
     * Encontrar el padre más cercano que tenga enlace
     */
    private function findParentWithContact($entity, array $entitiesWithContacts): ?array
    {
        $parent = null;

        // Verificar según el tipo de entidad
        if ($entity instanceof Barrio) {
            if ($entity->commune_id) {
                $parent = $entitiesWithContacts['commune_' . $entity->commune_id] ?? null;
            }
            if (!$parent && $entity->municipality_id) {
                $parent = $entitiesWithContacts['municipality_' . $entity->municipality_id] ?? null;
            }
        } elseif ($entity instanceof Vereda) {
            if ($entity->corregimiento_id) {
                $parent = $entitiesWithContacts['corregimiento_' . $entity->corregimiento_id] ?? null;
            }
            if (!$parent && $entity->municipality_id) {
                $parent = $entitiesWithContacts['municipality_' . $entity->municipality_id] ?? null;
            }
        } elseif ($entity instanceof Commune) {
            if ($entity->municipality_id) {
                $parent = $entitiesWithContacts['municipality_' . $entity->municipality_id] ?? null;
            }
        } elseif ($entity instanceof Corregimiento) {
            if ($entity->municipality_id) {
                $parent = $entitiesWithContacts['municipality_' . $entity->municipality_id] ?? null;
            }
        } elseif ($entity instanceof Municipality) {
            if ($entity->department_id) {
                $parent = $entitiesWithContacts['department_' . $entity->department_id] ?? null;
            }
        }

        return $parent;
    }

    /**
     * Obtener el tipo de entidad como string
     */
    private function getEntityType($entity): string
    {
        foreach (self::CONTACTABLE_TYPES as $type => $class) {
            if ($entity instanceof $class) {
                return $type;
            }
        }
        return 'unknown';
    }
}
