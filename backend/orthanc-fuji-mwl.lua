-- Fuji FCR Broad Query + carga MWM (error 21054 si falta StudyInstanceUID o la secuencia SPS).
--
-- El FCR envía modalidad/estación/fecha a nivel raíz (Broad Query). Orthanc solo matchea
-- esos filtros dentro de ScheduledProcedureStepSequence; hay que moverlos ahí.

local function firstNonEmpty(...)
  for i = 1, select('#', ...) do
    local value = select(i, ...)
    if value ~= nil and value ~= '' then
      return value
    end
  end

  return ''
end

function IncomingWorklistRequestFilter(query, origin)
  local rootModality = query['0008,0060'] or ''
  local rootStation = query['0040,0001'] or ''
  local rootDate = query['0040,0002'] or ''
  local rootTime = query['0040,0003'] or ''

  local spsItem = {}
  local sps = query['0040,0100']
  if type(sps) == 'table' and sps[1] ~= nil then
    spsItem = sps[1]
  end

  local modality = firstNonEmpty(spsItem['0008,0060'], rootModality)
  local station = firstNonEmpty(spsItem['0040,0001'], rootStation)
  local date = firstNonEmpty(spsItem['0040,0002'], rootDate)
  local time = firstNonEmpty(spsItem['0040,0003'], rootTime)

  query['0008,0020'] = ''
  query['0008,0030'] = ''
  query['0008,0050'] = ''
  query['0010,0010'] = ''
  query['0010,0020'] = ''
  query['0010,0030'] = ''
  query['0010,0040'] = ''
  query['0020,000d'] = ''
  query['0020,0010'] = ''
  query['0032,1060'] = ''
  query['0040,1001'] = ''

  query['0008,0060'] = ''
  query['0040,0001'] = ''
  query['0040,0002'] = ''
  query['0040,0003'] = ''

  query['0040,0100'] = {
    {
      ['0008,0060'] = modality,
      ['0040,0001'] = station,
      ['0040,0002'] = date,
      ['0040,0003'] = time,
      ['0040,0007'] = spsItem['0040,0007'] or '',
      ['0040,0009'] = spsItem['0040,0009'] or '',
    }
  }

  return query
end
