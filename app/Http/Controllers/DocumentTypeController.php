<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentTypeRequest;
use App\Http\Requests\UpdateDocumentTypeRequest;
use App\Models\DocumentType;

class DocumentTypeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $documentTypes = DocumentType::all(); // Récupère tous les types de documents
        return response()->json($documentTypes); // Retourne les types de documents en JSON
     }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreDocumentTypeRequest $request)
    {
        $validatedData = $request->validated(); // Valide et récupère les données

        $documentType = DocumentType::create($validatedData); // Crée un nouveau type de document

        // return response()->json($documentType, 201); // Retourne le type de document créé avec un code HTTP 201
        return response()->json([
            'success' => true,
            'message' => 'DocumentType créé avec succès.',
            'documentType' => $documentType
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(DocumentType $documentType)
    {
        return response()->json($documentType); // Retourne le type de document demandé en JSON
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(DocumentType $documentType)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateDocumentTypeRequest $request, DocumentType $documentType)
    {
        $validatedData = $request->validated(); // Valide et récupère les données

        $documentType->update($validatedData); // Met à jour le type de document

        // return response()->json($documentType); // Retourne le type de document mis à jour
        return response()->json([
            'success' => true,
            'message' => 'DocumentType mis à jour avec succès.',
            'documentType' => $documentType
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(DocumentType $documentType)
    {
        $documentType->delete(); // Supprime le type de document

        // return response()->json(null, 204); // Retourne une réponse vide avec un code HTTP 204 pour indiquer que la suppression a réussi
        return response()->json([
            'success' => true,
            'message' => 'DocumentType supprimé avec succès.',
            'documentType' => $documentType
        ]);
    }
}
