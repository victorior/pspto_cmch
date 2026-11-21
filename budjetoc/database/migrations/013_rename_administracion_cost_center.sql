-- Renombra el centro de costo / coordinacion "Administracion" a "Administracioncc"
-- para distinguirlo de la division estandar "Administracion." (con punto).

UPDATE cost_centers
SET name = 'Administracióncc'
WHERE name = 'Administración';

UPDATE departments
SET name = 'Administracióncc'
WHERE type = 'coordinacion'
  AND name = 'Administración';
